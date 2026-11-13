<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Sfs\SfsReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WPMAP-349: reports follow https://www.stopforumspam.com/usage ("Adding to the
 * Database"): POST to https://www.stopforumspam.com/add with username, ip_addr,
 * email, evidence and api_key. "a 200 will indicate a successful submission,
 * whereas a 403 will indicate an unsuccessful attempt. A reason will be given
 * (inside a paragraph tag)". No request leaves the process: wp_remote_post is
 * the recording stub in tests/bootstrap.php.
 */
#[CoversClass(SfsReporter::class)]
final class SfsReporterTest extends TestCase
{
    private const SFS_403 = '<html><body><p>Error: username field is required</p></body></html>';

    /** @var Settings&MockObject */
    private Settings $settings;

    /** @var Logger&MockObject */
    private Logger $logger;

    private bool $enabled = true;
    private string $api_key = 'test-key';

    protected function setUp(): void
    {
        $GLOBALS['bfas_test_http_requests'] = [];
        $GLOBALS['bfas_test_scheduled']     = [];
        $GLOBALS['bfas_test_transients']    = [];
        $GLOBALS['bfas_test_transient_ttl'] = [];
        $GLOBALS['bfas_test_actions']       = [];
        $GLOBALS['bfas_test_hooks']         = [];
        unset($GLOBALS['bfas_test_http_response']);

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => 'sfs_enabled' === $key ? $this->enabled : $default
        );
        $this->settings->method('get_sfs_api_key')->willReturnCallback(fn (): string => $this->api_key);
        $this->settings->method('get_hmac_secret')->willReturn('test-secret');

        $this->logger = $this->createMock(Logger::class);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['bfas_test_http_requests'],
            $GLOBALS['bfas_test_http_response'],
            $GLOBALS['bfas_test_scheduled'],
            $GLOBALS['bfas_test_actions']
        );
        $GLOBALS['bfas_test_transients'] = [];
        $GLOBALS['bfas_test_hooks']      = [];
    }

    private function reporter(): SfsReporter
    {
        return new SfsReporter($this->settings, $this->logger);
    }

    /** @param array<string, mixed> $response */
    private function respond(array $response): void
    {
        $GLOBALS['bfas_test_http_response'] = $response;
    }

    // ------------------------------------------------------------------ //
    // The request matches the documented add API
    // ------------------------------------------------------------------ //

    public function test_report_posts_the_documented_fields_to_the_add_endpoint(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '']);

        $this->reporter()->do_report('203.0.113.9', 'spammer.bot@example.com', 7);

        $this->assertCount(1, $GLOBALS['bfas_test_http_requests']);
        $request = $GLOBALS['bfas_test_http_requests'][0];
        $this->assertSame('https://www.stopforumspam.com/add', $request['url']);
        $this->assertSame(
            [
                'username' => 'spammer.bot',
                'ip_addr'  => '203.0.113.9',
                'email'    => 'spammer.bot@example.com',
                'evidence' => 'Blocked by BlueFairy Anti-Spam',
                'api_key'  => 'test-key',
            ],
            $request['args']['body']
        );
        $this->assertSame(10, $request['args']['timeout']);
    }

    public function test_a_200_marks_the_log_row_reported(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '']);
        $this->logger->expects($this->once())->method('mark_reported')->with(7);

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame([], $GLOBALS['bfas_test_actions']);
    }

    public function test_a_403_is_not_marked_and_its_reason_is_passed_on(): void
    {
        $this->respond(['response' => ['code' => 403], 'body' => self::SFS_403]);
        $this->logger->expects($this->never())->method('mark_reported');

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame(
            [['bfas_sfs_report_refused', [403, 'Error: username field is required', 7]]],
            $GLOBALS['bfas_test_actions']
        );
    }

    public function test_a_refusal_without_a_paragraph_passes_on_the_trimmed_body(): void
    {
        $this->respond(['response' => ['code' => 403], 'body' => "  invalid api key \n"]);

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame([403, 'invalid api key', 7], $GLOBALS['bfas_test_actions'][0][1]);
    }

    public function test_a_transport_error_is_not_marked_and_its_message_is_passed_on(): void
    {
        $this->logger->expects($this->never())->method('mark_reported');

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame(
            [['bfas_sfs_report_refused', [0, 'stubbed: no network in tests', 7]]],
            $GLOBALS['bfas_test_actions']
        );
    }

    public function test_without_an_api_key_nothing_is_sent(): void
    {
        $this->api_key = '';

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame([], $GLOBALS['bfas_test_http_requests']);
    }

    /** @return array<string, array{string, string}> */
    public static function unreportableRows(): array
    {
        // SFS requires username, email and IP; a report without them is refused.
        return [
            'no email'         => ['203.0.113.9', ''],
            'whitespace email' => ['203.0.113.9', '   '],
            'no local part'    => ['203.0.113.9', '@example.com'],
            'no @ at all'      => ['203.0.113.9', 'not-an-email'],
            'no ip'            => ['', 'spammer@example.com'],
            'whitespace ip'    => ['  ', 'spammer@example.com'],
        ];
    }

    #[DataProvider('unreportableRows')]
    public function test_an_unreportable_row_is_neither_sent_nor_scheduled(string $ip, string $email): void
    {
        $this->reporter()->do_report($ip, $email, 7);
        $this->reporter()->maybe_schedule_report($ip, $email, 7);

        $this->assertSame([], $GLOBALS['bfas_test_http_requests']);
        $this->assertSame([], $GLOBALS['bfas_test_scheduled']);
        $this->assertSame([], $GLOBALS['bfas_test_transients']);
    }

    public function test_the_email_is_trimmed_before_it_is_sent(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '']);

        $this->reporter()->do_report('203.0.113.9', "  spammer@example.com\n", 7);

        $body = $GLOBALS['bfas_test_http_requests'][0]['args']['body'];
        $this->assertSame('spammer', $body['username']);
        $this->assertSame('spammer@example.com', $body['email']);
    }

    public function test_a_long_refusal_reason_is_cut_to_200_characters(): void
    {
        $this->respond(['response' => ['code' => 403], 'body' => '<p>' . str_repeat('x', 500) . '</p>']);

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame(str_repeat('x', 200), $GLOBALS['bfas_test_actions'][0][1][1]);
    }

    public function test_a_refusal_reason_is_kept_to_one_line(): void
    {
        $this->respond(['response' => ['code' => 403], 'body' => "<p>bad key\nFAKE: forged line\r\n\tend</p>"]);

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame('bad key FAKE: forged line end', $GLOBALS['bfas_test_actions'][0][1][1]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_with_wp_debug_log_on_a_refusal_is_written_to_the_debug_log(): void
    {
        define('WP_DEBUG_LOG', true);
        $log = tempnam(sys_get_temp_dir(), 'bfas');
        ini_set('error_log', (string) $log);
        $this->respond(['response' => ['code' => 403], 'body' => self::SFS_403]);

        $this->reporter()->do_report('203.0.113.9', 'spammer@example.com', 7);

        $written = (string) file_get_contents((string) $log);
        unlink((string) $log);
        $this->assertStringContainsString(
            'Stop Forum Spam refused report for log row 7 (HTTP 403): Error: username field is required',
            $written
        );
    }

    // ------------------------------------------------------------------ //
    // register() and maybe_schedule_report()
    // ------------------------------------------------------------------ //

    public function test_register_hooks_the_report_event(): void
    {
        $reporter = $this->reporter();
        $reporter->register();

        $this->assertSame([[$reporter, 'do_report']], $GLOBALS['bfas_test_hooks']['bfas_sfs_report_event']);
    }

    public function test_a_report_is_scheduled_once_per_day_for_an_address(): void
    {
        $reporter = $this->reporter();
        $reporter->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 7);
        $reporter->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 8);

        $this->assertSame(
            [['hook' => 'bfas_sfs_report_event', 'args' => ['203.0.113.9', 'spammer@example.com', 7]]],
            $GLOBALS['bfas_test_scheduled']
        );
    }

    /** @return array<string, array{array<string, mixed>|null, string}> */
    public static function failedResponses(): array
    {
        return [
            'refused (403)'              => [['response' => ['code' => 403], 'body' => self::SFS_403], 'spammer@example.com'],
            'server error'               => [['response' => ['code' => 503], 'body' => ''], 'spammer@example.com'],
            'transport error'            => [null, 'spammer@example.com'],
            'refused, email as entered'  => [['response' => ['code' => 403], 'body' => self::SFS_403], ' Spammer@Example.com '],
        ];
    }

    /** @param array<string, mixed>|null $response */
    #[DataProvider('failedResponses')]
    public function test_a_failed_report_waits_an_hour_then_can_be_scheduled_again(?array $response, string $email): void
    {
        if (null !== $response) {
            $this->respond($response);
        }
        $reporter = $this->reporter();
        $reporter->maybe_schedule_report('203.0.113.9', $email, 7);
        $reporter->do_report('203.0.113.9', $email, 7);

        // One dedup key, its window cut from a day to an hour.
        $this->assertCount(1, $GLOBALS['bfas_test_transients']);
        $this->assertSame([3600], array_values($GLOBALS['bfas_test_transient_ttl']));

        // Within the hour nothing new is queued; once it has passed, the next block is.
        $reporter->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 8);
        $GLOBALS['bfas_test_transients'] = [];
        $reporter->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 9);

        $this->assertSame([7, 9], array_map(static fn (array $e): int => $e['args'][2], $GLOBALS['bfas_test_scheduled']));
    }

    public function test_an_accepted_report_is_not_scheduled_again_within_the_day(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '']);
        $reporter = $this->reporter();
        $reporter->maybe_schedule_report('203.0.113.9', ' Spammer@example.com', 7);
        $reporter->do_report('203.0.113.9', ' Spammer@example.com', 7);
        $reporter->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 8);

        $this->assertCount(1, $GLOBALS['bfas_test_scheduled']);
        $this->assertSame([86400], array_values($GLOBALS['bfas_test_transient_ttl']));
    }

    public function test_nothing_is_scheduled_when_reporting_is_off(): void
    {
        $this->enabled = false;
        $this->reporter()->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 7);

        $this->enabled = true;
        $this->api_key = '';
        $this->reporter()->maybe_schedule_report('203.0.113.9', 'spammer@example.com', 7);

        $this->assertSame([], $GLOBALS['bfas_test_scheduled']);
    }
}
