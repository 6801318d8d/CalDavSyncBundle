<?php

namespace KimaiPlugin\CalDavSyncBundle\Command;

use KimaiPlugin\CalDavSyncBundle\Sync\CalendarSync;
use KimaiPlugin\CalDavSyncBundle\Sync\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Full synchronisation: the first copy of the existing timesheets, and the repair after changes Kimai made without its
 * events (database edits, imports) or after the calendar server was unreachable for a while. Safe to run at any time:
 * only missing or different events are written.
 */
#[AsCommand(name: 'caldav-sync:sync', description: 'Bring the CalDAV calendar in line with all finished timesheets')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly CalendarSync $sync,
        private readonly Settings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be written and deleted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->settings->isConfigured()) {
            $output->writeln('<error>CALDAV_SYNC_URL is not set: nothing to synchronise</error>');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $stats = $this->sync->syncAll($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('%s%s: %d written, %d unchanged, %d deleted', $dryRun ? '[dry run] ' : '',
            $this->settings->url, $stats['written'], $stats['unchanged'], $stats['deleted']));

        return Command::SUCCESS;
    }
}
