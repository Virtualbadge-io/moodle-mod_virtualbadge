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

/**
 * Tests for the Virtualbadge API client.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\local\api_client
 */
#[\PHPUnit\Framework\Attributes\CoversClass(api_client::class)]
final class api_client_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('apiurl', 'https://api.example.org/', 'mod_virtualbadge');
        set_config('apikey', 'key-main', 'mod_virtualbadge');
    }

    /**
     * Client with canned responses.
     *
     * @param array $responses list of [status, body, headers]
     * @return api_client
     */
    protected function client(array $responses): api_client {
        return new class ($responses) extends api_client {
            /** @var array */
            public array $requests = [];

            /** @var array */
            protected array $responses;

            /**
             * Constructor.
             *
             * @param array $responses
             */
            public function __construct(array $responses) {
                $this->responses = $responses;
            }

            /**
             * Record the request and return the next canned response.
             *
             * @param string $method
             * @param string $url
             * @param string $apikey
             * @param array|null $body
             * @return array
             */
            protected function send(string $method, string $url, string $apikey, ?array $body): array {
                $this->requests[] = [$method, $url, $apikey, $body];
                $next = array_shift($this->responses);
                if ($next instanceof api_exception) {
                    throw $next;
                }
                return [$next[0], json_encode($next[1]), $next[2] ?? []];
            }
        };
    }

    /**
     * Certificate as returned by the API.
     *
     * @param string $id
     * @param array $fields
     * @return array
     */
    protected static function certificate(string $id, array $fields): array {
        return ['id' => $id, 'name' => 'Zertifikat ' . $id, 'type' => 'certificate', 'status' => 'active',
            'dynamic_fields' => $fields];
    }

    public function test_templates_are_parsed_paged_and_cached(): void {
        $client = $this->client([
            [200, ['next' => 'page2', 'data' => [self::certificate('cert_1', ['Ort' => '', 'signature' => ['sig-a', 'sig-b']])]]],
            [200, ['next' => null, 'data' => [self::certificate('cert_2', [])]]],
        ]);

        $templates = $client->templates('main');
        $this->assertSame($templates, $client->templates('main'));

        $this->assertSame([
            ['id' => 'cert_1', 'name' => 'Zertifikat cert_1', 'type' => 'certificate', 'fields' => ['Ort'],
                'slots' => ['signature' => 'sig-a']],
            ['id' => 'cert_2', 'name' => 'Zertifikat cert_2', 'type' => 'certificate', 'fields' => [], 'slots' => []],
        ], $templates);
        $this->assertCount(2, $client->requests);
        $this->assertSame('https://api.example.org/v2/public/certificate/?status=active&page=1', $client->requests[0][1]);
        $this->assertSame('key-main', $client->requests[0][2]);
    }

    public function test_refresh_waits_thirty_seconds(): void {
        $client = $this->client([
            [200, ['data' => [self::certificate('cert_1', ['Ort' => ''])]]],
            [200, ['data' => [self::certificate('cert_1', ['Ort' => '', 'Dauer' => ''])]]],
        ]);
        $client->templates('main');
        $this->assertSame(['Ort'], $client->templates('main', true)[0]['fields']);
        $this->assertCount(1, $client->requests);

        $cache = \cache::make('mod_virtualbadge', 'templates');
        $key = sha1('https://api.example.org|key-main');
        $entry = $cache->get($key);
        $entry['time'] -= api_client::REFRESH_MIN_INTERVAL + 1;
        $cache->set($key, $entry);

        $this->assertSame(['Ort', 'Dauer'], $client->templates('main', true)[0]['fields']);
        $this->assertCount(2, $client->requests);
    }

    public function test_issue_and_result_mapping(): void {
        $recipient = ['email' => 'a@example.org', 'full_name' => 'A B', 'issue_date' => '2026-10-24',
            'dynamic_fields' => ['Ort' => 'Hamburg'], 'metadata' => ['moodle_ref' => 'x:1:2']];
        $client = $this->client([[201, ['count' => 1, 'data' => [[
            'id' => 'recp_1',
            'generation_state' => 'pending',
            'email_status' => 'not sent',
            'access_page_url' => 'https://app.example.org/a?token=t',
            'validation_page_url' => 'https://www.example.org/v',
        ]]]]]);

        $result = $client->issue('main', 'cert_1', $recipient, true);

        $this->assertSame([
            'id' => 'recp_1',
            'status' => 'pending',
            'certificate_url' => 'https://app.example.org/a?token=t',
            'validation_url' => 'https://www.example.org/v',
            'wallet_url' => null,
            'email_status' => 'not sent',
        ], $result);
        [$method, $url, , $body] = $client->requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://api.example.org/v2/public/recipient/', $url);
        $this->assertSame(['certificate' => 'cert_1', 'send_emails' => true, 'add_duplicates' => true,
            'recipients' => [$recipient]], $body);

        $this->assertSame('issued', api_client::result(['id' => 'r', 'generation_state' => 'generated'])['status']);
        $this->assertSame('revoked', api_client::result(['id' => 'r', 'generation_state' => 'generated',
            'email_status' => 'revoked'])['status']);
    }

    public function test_replace_and_send_email(): void {
        $client = $this->client([
            [201, ['id' => 'recp_new', 'generation_state' => 'pending', 'email_status' => 'not sent',
                'replacement_for' => 'recp_old']],
            [200, ['id' => 'recp_new', 'email_status' => 'pending']],
        ]);
        $recipient = [
            'email' => 'a@example.org',
            'full_name' => 'A B',
            'dynamic_fields' => [],
            'metadata' => ['moodle_ref' => 'x'],
        ];

        $result = $client->replace('main', 'recp_old', $recipient);
        $client->send_email('main', 'recp_new');

        $this->assertSame('recp_new', $result['id']);
        $this->assertSame('https://api.example.org/v2/public/recipient/recp_old/replace/', $client->requests[0][1]);
        $this->assertArrayNotHasKey('metadata', $client->requests[0][3]);
        $this->assertSame(
            ['POST', 'https://api.example.org/v2/public/recipient/recp_new/send-email/'],
            [$client->requests[1][0], $client->requests[1][1]]
        );
    }

    public function test_find_replacement(): void {
        $client = $this->client([[200, ['next' => null, 'data' => [
            ['id' => 'recp_other', 'replacement_for' => null],
            ['id' => 'recp_new', 'replacement_for' => 'recp_old', 'generation_state' => 'generated'],
        ]]]]);
        $this->assertSame('recp_new', $client->find_replacement('main', 'cert_1', 'a@example.org', 'recp_old')['id']);
        $this->assertStringContainsString('certificate=cert_1&email=a%40example.org', $client->requests[0][1]);
    }

    public function test_find_by_reference(): void {
        $client = $this->client([[200, ['data' => []]]]);
        $this->assertNull($client->find('main', 'cert_1', 'abc:5:7'));
        $this->assertSame(
            'https://api.example.org/v2/public/recipient/?certificate=cert_1&metadata.moodle_ref=abc%3A5%3A7',
            $client->requests[0][1]
        );
    }

    public function test_further_organisation_uses_its_key(): void {
        set_config('apikey_partner', 'key-partner', 'mod_virtualbadge');
        $client = $this->client([[200, ['data' => []]]]);
        $client->templates('partner');
        $this->assertSame('key-partner', $client->requests[0][2]);
        $this->assertFalse($client->is_configured('other'));
    }

    /**
     * Error responses and whether they are temporary.
     *
     * @return array
     */
    public static function error_provider(): array {
        return [
            'rate limit' => [429, ['Retry-After' => '120'], true, 120],
            'server error' => [502, [], true, null],
            'invalid request' => [400, [], false, null],
            'unauthorised' => [401, [], false, null],
            'not found' => [404, [], false, null],
        ];
    }

    /**
     * Error mapping.
     *
     * @dataProvider error_provider
     * @param int $status
     * @param array $headers
     * @param bool $temporary
     * @param int|null $retryafter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('error_provider')]
    public function test_errors(int $status, array $headers, bool $temporary, ?int $retryafter): void {
        $client = $this->client([[$status, ['detail' => 'failure'], $headers]]);
        try {
            $client->status('main', 'recp_1');
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertSame($status, $e->status);
            $this->assertSame($temporary, $e->is_temporary());
            $this->assertSame($retryafter, $e->retryafter);
        }
    }

    public function test_network_error_is_temporary(): void {
        $client = $this->client([new api_exception(0, 'Connection refused')]);
        try {
            $client->status('main', 'recp_1');
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertTrue($e->is_temporary());
        }
    }

    public function test_hourly_limit(): void {
        $cache = \cache::make('mod_virtualbadge', 'ratelimit');
        $cache->set(sha1('https://api.example.org|key-main'), array_fill(0, config::DEFAULT_RATE_LIMIT, time() - 10));
        $client = $this->client([[200, ['data' => []]]]);
        try {
            $client->templates('main');
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertSame(429, $e->status);
            $this->assertTrue($e->is_temporary());
            $this->assertGreaterThan(3500, $e->retryafter);
        }
        $this->assertCount(0, $client->requests);
    }

    public function test_hourly_limit_is_configurable(): void {
        set_config('ratelimit', '2', 'mod_virtualbadge');
        set_config('apikey_partner', 'key-partner', 'mod_virtualbadge');
        set_config('ratelimit_partner', '0', 'mod_virtualbadge');
        $this->assertSame(2, config::rate_limit('main'));
        $this->assertSame(0, config::rate_limit('partner'));
        $this->assertSame(config::DEFAULT_RATE_LIMIT, config::rate_limit('other'));

        $client = $this->client(array_fill(0, 6, [200, ['id' => 'recp_1']]));
        $client->status('main', 'recp_1');
        $client->status('main', 'recp_1');
        try {
            $client->status('main', 'recp_1');
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertSame(429, $e->status);
        }
        for ($i = 0; $i < 3; $i++) {
            $client->status('partner', 'recp_1');
        }
        $this->assertCount(5, $client->requests);
    }

    public function test_not_configured(): void {
        set_config('apikey', '', 'mod_virtualbadge');
        $client = $this->client([]);
        $this->assertFalse($client->is_configured('main'));
        $this->expectException(api_exception::class);
        $client->templates('main');
    }

    public function test_links_and_ids_are_checked(): void {
        $result = api_client::result([
            'id' => 'recp_1',
            'generation_state' => 'generated',
            'access_page_url' => 'javascript:alert(1)',
            'validation_page_url' => 'http://www.virtualbadge.io/certificate-validator?credential=x',
        ]);
        $this->assertNull($result['certificate_url']);
        $this->assertNull($result['validation_url']);
        $url = 'https://app.virtualbadge.io/a/b?token=c';
        $this->assertSame($url, api_client::https_url($url));
        $this->assertSame('recp_Ab-1', api_client::id('recp_Ab-1'));
        $this->expectException(api_exception::class);
        api_client::id('../certificate');
    }

    public function test_api_key_is_stored_encrypted(): void {
        $this->resetAfterTest();
        set_config('apikey', \core\encryption::encrypt('key-secret'), 'mod_virtualbadge');
        $this->assertTrue(config::is_encrypted(get_config('mod_virtualbadge', 'apikey')));
        $this->assertSame('key-secret', config::api_key(config::MAIN_ORG));

        set_config('apikey', 'key-plain', 'mod_virtualbadge');
        $this->assertSame('key-plain', config::api_key(config::MAIN_ORG));

        set_config('apikey', 'sodium:broken', 'mod_virtualbadge');
        $this->assertSame('', config::api_key(config::MAIN_ORG));
        $this->assertDebuggingCalled();
    }
}
