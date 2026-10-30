<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_virtualbadge\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Client for the Virtualbadge.io API v2.
 *
 * Each organisation has its own API key. Templates are cached for ten minutes,
 * calls are limited per API key and hour as configured for the organisation.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** Seconds templates are kept in the cache. */
    public const TEMPLATE_TTL = 600;

    /** Minimum seconds between two forced template reloads. */
    public const REFRESH_MIN_INTERVAL = 30;

    /**
     * Whether URL and API key of an organisation are set.
     *
     * @param string $org
     * @return bool
     */
    public function is_configured(string $org): bool {
        return config::api_key($org) !== '';
    }

    /**
     * Active templates of an organisation.
     *
     * @param string $org
     * @param bool $refresh load again unless loaded in the last 30 seconds
     * @return array list of ['id', 'name', 'type', 'fields' => string[], 'slots' => [slug => default slot]]
     */
    public function templates(string $org, bool $refresh = false): array {
        $cache = \cache::make('mod_virtualbadge', 'templates');
        $key = $this->key_hash($org);
        $hit = $cache->get($key);
        $maxage = $refresh ? self::REFRESH_MIN_INTERVAL : self::TEMPLATE_TTL;
        if ($hit !== false && time() - $hit['time'] < $maxage) {
            return $hit['templates'];
        }

        $templates = [];
        $page = 1;
        do {
            $result = $this->request($org, 'GET', 'certificate/', ['status' => 'active', 'page' => $page]);
            foreach ($result['data'] ?? [] as $certificate) {
                $templates[] = self::template($certificate);
            }
            $page++;
        } while (!empty($result['next']));

        $cache->set($key, ['time' => time(), 'templates' => $templates]);
        return $templates;
    }

    /**
     * Template record from a certificate of the API.
     *
     * @param array $certificate
     * @return array
     */
    public static function template(array $certificate): array {
        $fields = [];
        $slots = [];
        foreach ($certificate['dynamic_fields'] ?? [] as $name => $value) {
            if (is_array($value)) {
                if ($value) {
                    $slots[(string) $name] = (string) reset($value);
                }
            } else {
                $fields[] = (string) $name;
            }
        }
        return [
            'id' => (string) $certificate['id'],
            'name' => (string) $certificate['name'],
            'type' => (string) ($certificate['type'] ?? 'certificate'),
            'fields' => $fields,
            'slots' => $slots,
        ];
    }

    /**
     * Create one recipient.
     *
     * @param string $org
     * @param string $templateid
     * @param array $recipient email, full_name, issue_date, dynamic_fields, metadata
     * @param bool $sendemail
     * @return array issue result, see {@see self::result()}
     */
    public function issue(string $org, string $templateid, array $recipient, bool $sendemail): array {
        $response = $this->request($org, 'POST', 'recipient/', [], [
            'certificate' => $templateid,
            'send_emails' => $sendemail,
            'add_duplicates' => true,
            'recipients' => [$recipient],
        ]);
        if (empty($response['data'][0])) {
            throw new api_exception(502, 'no recipient in response');
        }
        return self::result($response['data'][0]);
    }

    /**
     * Recipient created earlier with the given Moodle reference.
     *
     * @param string $org
     * @param string $templateid
     * @param string $reference
     * @return array|null issue result
     */
    public function find(string $org, string $templateid, string $reference): ?array {
        $response = $this->request($org, 'GET', 'recipient/', [
            'certificate' => $templateid,
            'metadata.moodle_ref' => $reference,
        ]);
        return empty($response['data'][0]) ? null : self::result($response['data'][0]);
    }

    /**
     * Replace a recipient by a new one with current data. The old recipient becomes void.
     *
     * @param string $org
     * @param string $remoteid recipient to replace
     * @param array $recipient email, full_name, issue_date, dynamic_fields
     * @return array issue result of the new recipient
     */
    public function replace(string $org, string $remoteid, array $recipient): array {
        unset($recipient['metadata']);
        return self::result($this->request($org, 'POST', 'recipient/' . self::id($remoteid) . '/replace/', [], $recipient));
    }

    /**
     * Recipient that replaced the given one.
     *
     * @param string $org
     * @param string $templateid
     * @param string $email
     * @param string $remoteid replaced recipient
     * @return array|null issue result
     */
    public function find_replacement(string $org, string $templateid, string $email, string $remoteid): ?array {
        $page = 1;
        do {
            $params = ['certificate' => $templateid, 'email' => $email, 'page' => $page];
            $response = $this->request($org, 'GET', 'recipient/', $params);
            foreach ($response['data'] ?? [] as $recipient) {
                if (($recipient['replacement_for'] ?? null) === $remoteid) {
                    return self::result($recipient);
                }
            }
            $page++;
        } while (!empty($response['next']));
        return null;
    }

    /**
     * Ask Virtualbadge to send the email with the certificate link again.
     *
     * @param string $org
     * @param string $remoteid
     * @return array issue result
     */
    public function send_email(string $org, string $remoteid): array {
        $response = $this->request($org, 'POST', 'recipient/' . self::id($remoteid) . '/send-email/', [], []);
        return isset($response['id']) ? self::result($response) : ['id' => $remoteid];
    }

    /**
     * Current state of a recipient.
     *
     * @param string $org
     * @param string $remoteid
     * @return array issue result
     */
    public function status(string $org, string $remoteid): array {
        return self::result($this->request($org, 'GET', 'recipient/' . self::id($remoteid) . '/'));
    }

    /**
     * Issue result from a recipient of the API.
     *
     * @param array $recipient
     * @return array id, status, certificate_url, validation_url, wallet_url, email_status
     */
    public static function result(array $recipient): array {
        if (in_array($recipient['email_status'] ?? '', ['revoked', 'voided'])) {
            $status = 'revoked';
        } else if (($recipient['generation_state'] ?? '') === 'generated') {
            $status = 'issued';
        } else {
            $status = 'pending';
        }
        return [
            'id' => (string) $recipient['id'],
            'status' => $status,
            'certificate_url' => self::https_url($recipient['access_page_url'] ?? null),
            'validation_url' => self::https_url($recipient['validation_page_url'] ?? null),
            'wallet_url' => null,
            'email_status' => $recipient['email_status'] ?? null,
        ];
    }

    /**
     * A link from Virtualbadge, only if it is a valid https URL.
     *
     * @param mixed $url
     * @return string|null
     */
    public static function https_url($url): ?string {
        $url = clean_param(trim((string) $url), PARAM_URL);
        return preg_match('~^https://[^/?#]+~i', $url) ? $url : null;
    }

    /**
     * Id of a Virtualbadge object for use in a request path.
     *
     * @param string $id
     * @return string
     * @throws api_exception if the id has unexpected characters
     */
    public static function id(string $id): string {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new api_exception(400, 'invalid id');
        }
        return $id;
    }

    /**
     * Send a request to the API.
     *
     * @param string $org
     * @param string $method
     * @param string $path relative to /v2/public/
     * @param array $params query parameters
     * @param array|null $body JSON body
     * @return array decoded response
     */
    protected function request(string $org, string $method, string $path, array $params = [], ?array $body = null): array {
        $apikey = config::api_key($org);
        if ($apikey === '') {
            throw new api_exception(401, get_string('apinotconfigured', 'mod_virtualbadge'));
        }
        $this->take_slot($org);

        $url = config::api_url() . '/v2/public/' . $path . ($params ? '?' . http_build_query($params, '', '&') : '');
        [$status, $response, $headers] = $this->send($method, $url, $apikey, $body);
        $decoded = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : substr($response, 0, 500);
            if ($status === 401 || $status === 403) {
                $detail = get_string('apiunauthorised', 'mod_virtualbadge');
            }
            throw new api_exception($status, $detail, $status === 429 ? ($this->retry_after($headers) ?? 60) : null);
        }
        if (!is_array($decoded)) {
            throw new api_exception(502, 'invalid JSON');
        }
        return $decoded;
    }

    /**
     * Perform the HTTP request.
     *
     * @param string $method
     * @param string $url
     * @param string $apikey
     * @param array|null $body
     * @return array [int status, string body, array headers]
     */
    protected function send(string $method, string $url, string $apikey, ?array $body): array {
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $apikey,
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        $options = ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10];
        if ($method === 'POST') {
            $response = $curl->post($url, json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE), $options);
        } else {
            $response = $curl->get($url, [], $options);
        }
        if ($curl->get_errno() || $curl->error) {
            throw new api_exception(0, $curl->error ?: 'curl error ' . $curl->get_errno());
        }
        return [(int) ($curl->get_info()['http_code'] ?? 0), (string) $response, $curl->getResponse()];
    }

    /**
     * Reserve one call within the hourly limit of the organisation's API key.
     *
     * @param string $org
     */
    protected function take_slot(string $org): void {
        $limit = config::rate_limit($org);
        if ($limit === 0) {
            return;
        }
        $cache = \cache::make('mod_virtualbadge', 'ratelimit');
        $key = $this->key_hash($org);
        $now = time();
        $calls = array_values(array_filter($cache->get($key) ?: [], fn($t) => $t > $now - 3600));
        if (count($calls) >= $limit) {
            throw new api_exception(429, get_string('apiratelimit', 'mod_virtualbadge'), $calls[0] + 3600 - $now);
        }
        $calls[] = $now;
        $cache->set($key, $calls);
    }

    /**
     * Cache key for an organisation: same API URL and key share cache and limit.
     *
     * @param string $org
     * @return string
     */
    protected function key_hash(string $org): string {
        return sha1(config::api_url() . '|' . config::api_key($org));
    }

    /**
     * Retry-After value in seconds from response headers.
     *
     * @param array $headers
     * @return int|null
     */
    protected function retry_after(array $headers): ?int {
        foreach ($headers as $name => $value) {
            if (strcasecmp(trim($name), 'Retry-After') === 0 && is_numeric(trim($value))) {
                return (int) trim($value);
            }
        }
        return null;
    }
}
