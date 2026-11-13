<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Sfs\SfsReporter;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function test_without_a_usable_email_nothing_is_sent(): void
    {
        // SFS requires username, email and IP; a report without them is refused.
        $this->reporter()->do_report('203.0.113.9', '', 7);
        $this->reporter()->do_report('203.0.113.9', '@example.com', 7);
        $this->reporter()->do_report('', 'spammer@example.com', 7);

        $this->assertSame([], $GLOBALS['bfas_test_http_requests']);
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
