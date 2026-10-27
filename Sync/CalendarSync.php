<?php

namespace KimaiPlugin\CalDavSyncBundle\Sync;

use App\Entity\Timesheet;
use App\Repository\TimesheetRepository;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\CalDavSyncBundle\CalDav\CalDavClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keeps the CalDAV calendar in step with Kimai, one way.
 *
 * During a request the timesheets that were created, changed, stopped or deleted are only noted (touch()); at the end of
 * the request, after the response is sent and the database transaction is over, flush() reads each of them again: a
 * finished timesheet of an included user is written to the calendar, anything else (deleted, running again, another
 * user's) is removed from it. A running timer therefore reaches the calendar only when it is stopped.
 * Timesheets that could not be written (server down, wrong password) wait in var/data/caldav-sync-pending.json and are
 * retried with the next change, or by "bin/console caldav-sync:sync"; the first failure and the recovery are pushed to
 * ntfy when CALDAV_SYNC_NTFY_URL is set. Calendar errors never break Kimai: they are only logged.
 */
final class CalendarSync
{
    /** @var array<int, true> */
    private array $touched = [];
    private ?bool $calendarChecked = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly CalDavClient $client,
        private readonly EventFactory $factory,
        private readonly TimesheetRepository $timesheets,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    public function touch(Timesheet $timesheet): void
    {
        if ($timesheet->getId() !== null) {
            $this->touched[$timesheet->getId()] = true;
        }
    }

    /** Writes the timesheets touched during this request, plus those still pending from earlier failures. */
    public function flush(): void
    {
        if ($this->touched === [] || !$this->settings->isConfigured()) {
            $this->touched = [];

            return;
        }
        try {
            $pending = $this->readPending();
            $ids = array_keys($this->touched + array_fill_keys($pending['ids'], true));
            $this->touched = [];
            $failed = [];
            $error = null;
            foreach ($ids as $id) {
                try {
                    $this->syncOne($id);
                } catch (\Throwable $e) {
                    $failed[] = $id;
                    $error = $e->getMessage();
                }
            }
            $this->writePending($failed, $error, $pending);
        } catch (\Throwable $e) {
            $this->logger->error('caldav_sync: ' . $e->getMessage());
        }
    }

    /**
     * Brings the whole calendar in line with Kimai: writes every finished timesheet whose event is missing or differs,
     * deletes the plugin's events whose timesheet is gone, and empties the pending list.
     *
     * @return array{written: int, unchanged: int, deleted: int}
     */
    public function syncAll(bool $dryRun = false): array
    {
        $this->ensureCalendar($dryRun);
        $remote = [];
        if (!$dryRun || $this->calendarChecked) {
            foreach ($this->client->events() as $resource => $data) {
                if (EventFactory::isOwn($resource)) {
                    $remote[$resource] = EventFactory::normalize($data);
                }
            }
        }
        $stats = ['written' => 0, 'unchanged' => 0, 'deleted' => 0];
        $query = $this->timesheets->createQueryBuilder('t')
            ->where('t.end IS NOT NULL')
            ->orderBy('t.begin', 'ASC')
            ->getQuery();
        $count = 0;
        foreach ($query->toIterable() as $timesheet) {
            /** @var Timesheet $timesheet */
            if (!$this->settings->includesUser((string) $timesheet->getUser()?->getUserIdentifier())) {
                continue;
            }
            $resource = EventFactory::resourceName($timesheet->getId());
            $ics = $this->factory->build($timesheet);
            if (isset($remote[$resource]) && $remote[$resource] === EventFactory::normalize($ics)) {
                ++$stats['unchanged'];
            } else {
                if (!$dryRun) {
                    $this->client->put($resource, $ics);
                }
                ++$stats['written'];
            }
            unset($remote[$resource]);
            if (++$count % 200 === 0) {
                $this->entityManager->clear();  // keeps memory flat on large databases
            }
        }
        foreach (array_keys($remote) as $resource) {
            if (!$dryRun) {
                $this->client->delete($resource);
            }
            ++$stats['deleted'];
        }
        if (!$dryRun) {
            $this->writePending([], null, $this->readPending());
        }

        return $stats;
    }

    private function syncOne(int $id): void
    {
        $timesheet = $this->timesheets->find($id);
        $resource = EventFactory::resourceName($id);
        if ($timesheet instanceof Timesheet && $timesheet->getEnd() !== null
            && $this->settings->includesUser((string) $timesheet->getUser()?->getUserIdentifier())) {
            $this->ensureCalendar(false);
            $this->client->put($resource, $this->factory->build($timesheet));
        } else {
            $this->client->delete($resource);
        }
    }

    /** Creates the calendar the first time it is needed, if the server does not have it yet. */
    private function ensureCalendar(bool $dryRun): void
    {
        if ($this->calendarChecked) {
            return;
        }
        $exists = $this->client->calendarExists();
        if (!$exists && !$dryRun) {
            $this->client->createCalendar($this->settings->calendarName, $this->settings->calendarColor);
            $this->logger->info('caldav_sync: calendar created at ' . $this->settings->url);
            $exists = true;
        }
        $this->calendarChecked = $exists;
    }

    // --- pending list and alerts ------------------------------------------------------------------------------------

    private function pendingFile(): string
    {
        return $this->projectDir . '/var/data/caldav-sync-pending.json';
    }

    /** @return array{ids: int[], failing: bool} */
    private function readPending(): array
    {
        $data = is_file($this->pendingFile()) ? json_decode((string) file_get_contents($this->pendingFile()), true) : null;

        return [
            'ids' => array_map('intval', \is_array($data['ids'] ?? null) ? $data['ids'] : []),
            'failing' => (bool) ($data['failing'] ?? false),
        ];
    }

    /**
     * @param int[] $failed
     * @param array{ids: int[], failing: bool} $before
     */
    private function writePending(array $failed, ?string $error, array $before): void
    {
        $failing = $failed !== [];
        if ($failing) {
            $this->logger->warning(\sprintf('caldav_sync: %d timesheet(s) not written, retried with the next change: %s', \count($failed), $error));
        }
        if ($failing || $before['ids'] !== [] || $before['failing']) {
            file_put_contents($this->pendingFile(), json_encode(['ids' => $failed, 'failing' => $failing, 'error' => $error]), LOCK_EX);
        }
        if ($failing && !$before['failing']) {
            $this->alert('Kimai: calendar sync failed', \sprintf("Timesheets not written: %d\nError: %s\nThey are retried with the next change, or run: bin/console caldav-sync:sync", \count($failed), mb_substr((string) $error, 0, 300)), 'x', 4);
        } elseif (!$failing && $before['failing']) {
            $this->alert('Kimai: calendar sync working again', 'The calendar is up to date with Kimai again.', 'white_check_mark', 3);
        }
    }

    private function alert(string $title, string $message, string $tag, int $priority): void
    {
        if ($this->settings->ntfyUrl === '') {
            return;
        }
        try {
            $headers = ['Title' => $title, 'Tags' => $tag, 'Priority' => (string) $priority];
            if ($this->settings->ntfyToken !== '') {
                $headers['Authorization'] = 'Bearer ' . $this->settings->ntfyToken;
            }
            $this->http->request('POST', $this->settings->ntfyUrl . '/' . rawurlencode($this->settings->ntfyTopic), [
                'headers' => $headers,
                'body' => $message,
                'timeout' => 3,
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('caldav_sync: ntfy alert failed: ' . $e->getMessage());
        }
    }
}
