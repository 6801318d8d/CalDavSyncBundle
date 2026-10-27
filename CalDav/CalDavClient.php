<?php

namespace KimaiPlugin\CalDavSyncBundle\CalDav;

use KimaiPlugin\CalDavSyncBundle\Sync\Settings;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A minimal client for one CalDAV calendar collection (RFC 4791), with HTTP basic authentication: nothing in it is
 * specific to a server, so it works with Nextcloud, Radicale, Baïkal, SOGo, Fastmail and the like. Calendar objects
 * are addressed by their resource name inside the collection (e.g. "kimai-12.ics").
 */
final class CalDavClient
{
    private const DAV = 'DAV:';
    private const CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Settings $settings,
    ) {
    }

    /** Whether the calendar collection exists. */
    public function calendarExists(): bool
    {
        $response = $this->request('PROPFIND', '', '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>', ['Depth' => '0']);
        $status = $response->getStatusCode();
        if ($status === 404) {
            return false;
        }
        $this->expect($response, [207], 'PROPFIND');

        return true;
    }

    /** Creates the calendar collection (MKCALENDAR), for events only. */
    public function createCalendar(string $name, string $color = ''): void
    {
        $props = '<d:displayname>' . htmlspecialchars($name, ENT_XML1) . '</d:displayname>';
        if ($color !== '') {
            $props .= '<a:calendar-color>' . htmlspecialchars($color, ENT_XML1) . '</a:calendar-color>';
        }
        $props .= '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>';
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">'
            . '<d:set><d:prop>' . $props . '</d:prop></d:set></c:mkcalendar>';
        $this->expect($this->request('MKCALENDAR', '', $body), [201], 'MKCALENDAR');
    }

    /** Creates or replaces a calendar object. */
    public function put(string $resource, string $ics): void
    {
        $response = $this->request('PUT', $resource, $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);
        $this->expect($response, [200, 201, 204], 'PUT ' . $resource);
    }

    /** Deletes a calendar object; one that is not there is no error. */
    public function delete(string $resource): void
    {
        $this->expect($this->request('DELETE', $resource), [200, 204, 404], 'DELETE ' . $resource);
    }

    /**
     * Every event of the calendar (calendar-query REPORT).
     *
     * @return array<string, string> resource name => iCalendar data
     */
    public function events(): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter>'
            . '</c:calendar-query>';
        $response = $this->request('REPORT', '', $body, ['Depth' => '1']);
        $this->expect($response, [207], 'REPORT');

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($response->getContent(false), LIBXML_NONET)) {
            throw new CalDavException('REPORT: the server answered with invalid XML');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', self::DAV);
        $xpath->registerNamespace('c', self::CALDAV);
        $events = [];
        foreach ($xpath->query('//d:response') as $item) {
            $href = trim((string) $xpath->evaluate('string(d:href)', $item));
            $data = (string) $xpath->evaluate('string(.//c:calendar-data)', $item);
            if ($href !== '' && $data !== '') {
                $events[rawurldecode(basename(parse_url($href, PHP_URL_PATH) ?: $href))] = $data;
            }
        }

        return $events;
    }

    private function request(string $method, string $resource, ?string $body = null, array $headers = []): ResponseInterface
    {
        $options = ['headers' => $headers + ['Content-Type' => 'application/xml; charset=utf-8'], 'timeout' => $this->settings->timeout];
        if ($this->settings->username !== '') {
            $options['auth_basic'] = [$this->settings->username, $this->settings->password];
        }
        if ($body !== null) {
            $options['body'] = $body;
        }

        return $this->http->request($method, $this->settings->url . rawurlencode($resource), $options);
    }

    /** @param int[] $accepted */
    private function expect(ResponseInterface $response, array $accepted, string $what): void
    {
        // the HTTP client is lazy: reading the status sends the request (and raises transport errors here)
        $status = $response->getStatusCode();
        if (!\in_array($status, $accepted, true)) {
            $detail = trim(strip_tags(mb_substr($response->getContent(false), 0, 300)));
            throw new CalDavException(\sprintf('%s: HTTP %d%s', $what, $status, $detail !== '' ? ' (' . preg_replace('/\s+/', ' ', $detail) . ')' : ''));
        }
    }
}
