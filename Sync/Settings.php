<?php

namespace KimaiPlugin\CalDavSyncBundle\Sync;

/**
 * The plugin's settings, from the environment (e.g. the container's environment in compose.yaml):
 *
 *   CALDAV_SYNC_URL            address of the CalDAV calendar collection (required; without it the plugin does nothing),
 *                              e.g. https://cloud.example.com/remote.php/dav/calendars/alice/kimai/
 *   CALDAV_SYNC_USERNAME       user for HTTP basic authentication
 *   CALDAV_SYNC_PASSWORD       its password (an app password where the server has them)
 *   CALDAV_SYNC_CALENDAR_NAME  name given to the calendar when the plugin creates it (default "Kimai")
 *   CALDAV_SYNC_CALENDAR_COLOR its colour then, e.g. #1e90ff (optional)
 *   CALDAV_SYNC_USERS          Kimai user names whose timesheets are copied, comma separated (default: everyone's)
 *   CALDAV_SYNC_KIMAI_URL      Kimai's public address: each event links to its timesheet (optional)
 *   CALDAV_SYNC_TIMEOUT        seconds per CalDAV request (default 5)
 *   CALDAV_SYNC_NTFY_URL       ntfy server for failure alerts (optional), e.g. https://ntfy.example.com
 *   CALDAV_SYNC_NTFY_TOPIC     its topic (default kimai-alerts)
 *   CALDAV_SYNC_NTFY_TOKEN     its access token (optional)
 */
final class Settings
{
    public readonly string $url;
    public readonly string $username;
    public readonly string $password;
    public readonly string $calendarName;
    public readonly string $calendarColor;
    /** @var string[] */
    public readonly array $users;
    public readonly string $kimaiUrl;
    public readonly float $timeout;
    public readonly string $ntfyUrl;
    public readonly string $ntfyTopic;
    public readonly string $ntfyToken;

    public function __construct()
    {
        $url = self::env('CALDAV_SYNC_URL');
        $this->url = $url === '' ? '' : rtrim($url, '/') . '/';
        $this->username = self::env('CALDAV_SYNC_USERNAME');
        $this->password = self::env('CALDAV_SYNC_PASSWORD');
        $this->calendarName = self::env('CALDAV_SYNC_CALENDAR_NAME', 'Kimai');
        $this->calendarColor = self::env('CALDAV_SYNC_CALENDAR_COLOR');
        $users = array_map('trim', explode(',', self::env('CALDAV_SYNC_USERS')));
        $this->users = array_values(array_filter($users, fn (string $u) => $u !== ''));
        $this->kimaiUrl = rtrim(self::env('CALDAV_SYNC_KIMAI_URL'), '/');
        $timeout = (float) self::env('CALDAV_SYNC_TIMEOUT', '5');
        $this->timeout = $timeout > 0 ? $timeout : 5.0;
        $this->ntfyUrl = rtrim(self::env('CALDAV_SYNC_NTFY_URL'), '/');
        $this->ntfyTopic = self::env('CALDAV_SYNC_NTFY_TOPIC', 'kimai-alerts');
        $this->ntfyToken = self::env('CALDAV_SYNC_NTFY_TOKEN');
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    /** Whether the timesheets of this Kimai user are copied. */
    public function includesUser(string $username): bool
    {
        return $this->users === [] || \in_array($username, $this->users, true);
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
