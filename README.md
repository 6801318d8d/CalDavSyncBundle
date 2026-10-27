# CalDavSyncBundle

A [Kimai](https://www.kimai.org) plugin that copies your tracked time into a calendar: every finished timesheet
becomes an event in a CalDAV calendar, as soon as it is saved. It works with any CalDAV server (Nextcloud, Radicale,
Baïkal, SOGo, Fastmail, ...), so the time you tracked shows up next to your appointments in any calendar app.

The copy goes one way only, from Kimai to the calendar:

| In Kimai | In the calendar |
|---|---|
| You stop the timer | The event appears, with its real begin and end |
| You add an entry with begin and end (form, duplicate, API, mobile app) | The event appears at once |
| You edit or delete a finished entry (also several at a time) | The event changes or disappears |
| A timer is running | Nothing yet: the event appears when you stop it |

Changes made in the calendar are not copied back, and the next change of the entry in Kimai overwrites them.

Each event has:

- **title** `Customer › Project · Activity`
- **begin and end** of the entry
- **description** of the entry
- **categories**: customer, project and the entry's tags
- **colour** of the activity (else of the project, else of the customer), as the nearest CSS colour name, which is what
  iCalendar allows
- **link** to the entry in Kimai (with `CALDAV_SYNC_KIMAI_URL`)
- **free** time (`TRANSP:TRANSPARENT`): tracked time does not make you busy for scheduling tools and free/busy lookups

The plugin only touches events it created (named `kimai-<id>.ics`), so the calendar can hold other events too.
A dedicated calendar is still the tidiest choice: if it does not exist, the plugin creates it.

## Requirements

Kimai 2. Tested with 2.69.0 and Nextcloud 34. A CalDAV server that accepts HTTP basic authentication.

## Configuration

Environment variables of the Kimai process (for Docker, the `environment` of the Kimai service):

| Variable | Meaning |
|---|---|
| `CALDAV_SYNC_URL` | Address of the calendar collection (required; without it the plugin does nothing) |
| `CALDAV_SYNC_USERNAME` | User of the CalDAV server |
| `CALDAV_SYNC_PASSWORD` | Its password; use an app password where the server has them |
| `CALDAV_SYNC_CALENDAR_NAME` | Name of the calendar if the plugin creates it (default `Kimai`) |
| `CALDAV_SYNC_CALENDAR_COLOR` | Its colour then, e.g. `#1e90ff` (optional) |
| `CALDAV_SYNC_USERS` | Kimai user names whose entries are copied, comma separated (default: everybody's) |
| `CALDAV_SYNC_KIMAI_URL` | Public address of your Kimai, for the link in each event (optional) |
| `CALDAV_SYNC_TIMEOUT` | Seconds per CalDAV request (default 5) |
| `CALDAV_SYNC_NTFY_URL` | An [ntfy](https://ntfy.sh) server to be told when the copy fails and when it works again (optional) |
| `CALDAV_SYNC_NTFY_TOPIC` | Its topic (default `kimai-alerts`) |
| `CALDAV_SYNC_NTFY_TOKEN` | Access token for the topic, if the server needs one |

Calendar addresses of some servers:

- Nextcloud: `https://cloud.example.com/remote.php/dav/calendars/<user>/<calendar>/`
- Radicale: `https://radicale.example.com/<user>/<calendar>/`
- Baïkal: `https://baikal.example.com/dav.php/calendars/<user>/<calendar>/`

For Nextcloud, create an app password (*Settings → Security → Devices & sessions*); the calendar's address is the
one *Copy private link* gives in the calendar's menu. If Kimai and Nextcloud run in the same
Docker network, Kimai can use Nextcloud's container name (e.g. `http://app/remote.php/dav/...`): add that name to
Nextcloud's `trusted_domains`.

Docker Compose example:

```yaml
services:
  kimai:
    environment:
      CALDAV_SYNC_URL: https://cloud.example.com/remote.php/dav/calendars/alice/kimai/
      CALDAV_SYNC_USERNAME: alice
      CALDAV_SYNC_PASSWORD: ${CALDAV_SYNC_PASSWORD}
      CALDAV_SYNC_KIMAI_URL: https://kimai.example.com
```

## Installation

The plugin directory must be called `CalDavSyncBundle`:

```sh
cd /path/to/kimai/var/plugins
git clone https://github.com/6801318d8d/CalDavSyncBundle.git
cd /path/to/kimai
bin/console kimai:reload --env=prod
bin/console caldav-sync:sync --env=prod
```

With the official Docker image (replace `kimai` with the name of your container):

```sh
git clone https://github.com/6801318d8d/CalDavSyncBundle.git
docker cp CalDavSyncBundle kimai:/opt/kimai/var/plugins/
docker exec kimai chown -R www-data:www-data /opt/kimai/var/plugins/CalDavSyncBundle
docker exec -u www-data kimai /opt/kimai/bin/console kimai:reload --env=prod
docker exec -u www-data kimai /opt/kimai/bin/console caldav-sync:sync --env=prod
```

Run the commands as `www-data`: run as root, `kimai:reload` leaves cache files that the web server cannot write.
Mount `/opt/kimai/var/plugins` on a volume, otherwise the plugin is lost when the container is recreated.
The environment variables reach Kimai only when the container is created again (`docker compose up -d`).

`caldav-sync:sync` copies the entries you already have; after that the plugin keeps the calendar up to date by
itself. To remove the plugin, delete its directory and run `kimai:reload` again; the events stay in the calendar.

## Full synchronisation

```sh
bin/console caldav-sync:sync --env=prod            # write missing or different events, delete orphaned ones
bin/console caldav-sync:sync --env=prod --dry-run  # only count them
```

It is safe to run at any time: unchanged events are not written again. Run it after the first installation, after
changes Kimai makes without its events (database edits, imports), or after the calendar server was unreachable for a
long time.

## When the calendar server is unreachable

Saving in Kimai never fails because of the calendar. Entries that could not be copied are kept in
`var/data/caldav-sync-pending.json` and retried with the next change in Kimai; `caldav-sync:sync` brings everything
back in line at once. Errors go to Kimai's log (`caldav_sync: ...`); with `CALDAV_SYNC_NTFY_URL`, ntfy gets one
message at the first failure and one when the copy works again.

## How it works

The plugin listens to Kimai's timesheet events (create, update, stop, duplicate, restart, delete, and the multiple
update and delete of the lists), which fire for the web pages, the API and the console alike. It only notes which
entries changed; at the end of the request (`kernel.terminate`, `console.terminate`), when the response has been
sent and the database transaction is over, it reads each of them again and writes it with `PUT` or removes it with
`DELETE` on the calendar collection. A running or deleted entry, or one of a user who is not listed, is removed.
Plain CalDAV only: `PROPFIND`, `MKCALENDAR`, `REPORT` (calendar-query), `PUT` and `DELETE` with basic
authentication. Nothing is stored in Kimai's database.

## License

MIT
