<?php

namespace KimaiPlugin\CalDavSyncBundle\Sync;

use App\Entity\Timesheet;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a finished timesheet into an iCalendar object with one event:
 *   SUMMARY      "Customer › Project · Activity"
 *   DTSTART/END  begin and end, in UTC
 *   DESCRIPTION  the timesheet's description
 *   CATEGORIES   customer, project and the timesheet's tags
 *   COLOR        the CSS3 name nearest to the activity's colour, else the project's, else the customer's
 *   URL          the timesheet in Kimai (only with CALDAV_SYNC_KIMAI_URL)
 *   TRANSP       TRANSPARENT: tracked time does not make the calendar owner busy (free/busy, schedulers)
 * Resource name "kimai-<id>.ics" and UID "kimai-<id>@<Kimai's host>": the plugin only ever touches objects named like
 * that, so the calendar can hold other events too.
 */
final class EventFactory
{
    private const PREFIX = 'kimai-';

    public function __construct(
        private readonly Settings $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function resourceName(int $id): string
    {
        return self::PREFIX . $id . '.ics';
    }

    /** Whether a resource of the calendar was written by this plugin. */
    public static function isOwn(string $resource): bool
    {
        return preg_match('/^' . self::PREFIX . '\d+\.ics$/', $resource) === 1;
    }

    public function build(Timesheet $timesheet): string
    {
        $project = $timesheet->getProject();
        $customer = $project?->getCustomer();
        $activity = $timesheet->getActivity();
        $title = ($customer?->getName() ?? '?') . ' › ' . ($project?->getName() ?? '?');
        if ($activity !== null && (string) $activity->getName() !== '') {
            $title .= ' · ' . $activity->getName();
        }
        $begin = \DateTimeImmutable::createFromInterface($timesheet->getBegin());
        $end = \DateTimeImmutable::createFromInterface($timesheet->getEnd());
        if ($end <= $begin) {  // iCalendar wants the end after the start
            $end = $begin->modify('+1 minute');
        }
        $host = $this->settings->kimaiUrl !== '' ? (parse_url($this->settings->kimaiUrl, PHP_URL_HOST) ?: 'kimai') : 'kimai';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kimai CalDavSyncBundle//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . self::PREFIX . $timesheet->getId() . '@' . $host,
            'DTSTAMP:' . self::utc(new \DateTimeImmutable()),
            'DTSTART:' . self::utc($begin),
            'DTEND:' . self::utc($end),
            'SUMMARY:' . self::text($title),
        ];
        $description = trim((string) $timesheet->getDescription());
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::text($description);
        }
        $categories = array_values(array_unique(array_filter(
            [$customer?->getName(), $project?->getName(), ...$timesheet->getTagsAsArray()],
            fn ($c) => $c !== null && trim((string) $c) !== '',
        )));
        if ($categories !== []) {
            $lines[] = 'CATEGORIES:' . implode(',', array_map(fn ($c) => self::text((string) $c), $categories));
        }
        $color = CssColors::nearest($activity?->getColor() ?: ($project?->getColor() ?: $customer?->getColor()));
        if ($color !== null) {
            $lines[] = 'COLOR:' . $color;
        }
        $link = $this->link($timesheet);
        if ($link !== null) {
            $lines[] = 'URL:' . $link;
        }
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * The same event in comparable form: unfolded lines, without DTSTAMP (the time it was written) and with the line
     * ends normalised, so that an unchanged timesheet is not written again.
     */
    public static function normalize(string $ics): string
    {
        $text = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
        $lines = array_filter(array_map('rtrim', explode("\n", $text)), fn ($l) => $l !== '' && !str_starts_with($l, 'DTSTAMP:'));

        return implode("\n", $lines);
    }

    private function link(Timesheet $timesheet): ?string
    {
        if ($this->settings->kimaiUrl === '') {
            return null;
        }
        foreach ([$timesheet->getUser()?->getLanguage(), 'en'] as $locale) {
            try {
                return $this->settings->kimaiUrl . $this->urls->generate('timesheet_edit', ['id' => $timesheet->getId(), '_locale' => $locale]);
            } catch (\Throwable) {
                // a language without its own route prefix: try English
            }
        }

        return null;
    }

    private static function utc(\DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** TEXT value escaping (RFC 5545, 3.3.11). */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $value);
    }

    /** Lines longer than 75 octets go on continuation lines, never splitting a UTF-8 character (RFC 5545, 3.1). */
    private static function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                $limit = 74;  // continuation lines start with a space, which counts towards their 75 octets
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
