<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Integration\WordPressIntegration;
use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Traps\Honeypot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WordPressIntegration::class)]
final class WordPressIntegrationTest extends TestCase
{
    private Settings $settings;
    private WordPressIntegration $integration;
    private bool $sfs = false;
    private bool $timetrap = true;

    protected function setUp(): void
    {
        $GLOBALS['bfas_test_hooks'] = [];
        $GLOBALS['bfas_test_posts'] = [
            10 => (object) ['post_type' => 'bfrc_recipe'],
            20 => (object) ['post_type' => 'post'],
        ];
        $GLOBALS['bfas_test_rest'] = false;
        $_POST                     = [];
        $_SERVER['REMOTE_ADDR']    = '203.0.113.9';

        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => match ($key) {
                'honeypot_enabled' => true,
                'timetrap_enabled' => $this->timetrap,
                'min_time_seconds' => 3,
                'sfs_enabled' => $this->sfs,
                'sfs_check_threshold' => 1,
                default => $default,
            }
        );
        $settings->method('get_hmac_secret')->willReturn('test-secret');
        $this->settings = $settings;

        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturn(1);

        $this->integration = new WordPressIntegration($settings, $logger);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['REMOTE_ADDR'], $GLOBALS['bfas_test_hooks'], $GLOBALS['bfas_test_posts'], $GLOBALS['bfas_test_rest'], $GLOBALS['bfas_test_transients'], $GLOBALS['bfas_test_logged_in']);
    }

    /**
     * The fields render_recipe_review_fields() prints, parsed back into a $_POST shape.
     *
     * @return array<string, string>
     */
    private function recipe_review_post(): array
    {
        ob_start();
        $this->integration->render_recipe_review_fields();
        $html = (string) ob_get_clean();
        preg_match_all('/name="([^"]+)" value="([^"]*)"/', $html, $m, PREG_SET_ORDER);
        $post = [];
        foreach ($m as $field) {
            $post[$field[1]] = html_entity_decode($field[2], ENT_QUOTES);
        }
        return $post;
    }

    /** A token minted long enough ago to pass the time trap. */
    private function aged_token(string $form_type): string
    {
        $nonce = 'n';
        $ts    = time() - 60;
        $hmac  = hash_hmac('sha256', $nonce . ':' . $ts . ':' . $form_type, 'test-secret');
        return base64_encode($nonce . '|' . $ts . '|' . $hmac);
    }

    /** @return array<string, mixed> */
    private function review(int $post_id = 10, string $type = WordPressIntegration::RECIPE_REVIEW_TYPE): array
    {
        return ['comment_post_ID' => $post_id, 'comment_type' => $type, 'comment_author_email' => 'sam@example.com'];
    }

    public function test_register_hooks_the_recipe_review_form(): void
    {
        $this->integration->register();
        $this->assertContains(
            [$this->integration, 'render_recipe_review_fields'],
            $GLOBALS['bfas_test_hooks'][WordPressIntegration::RECIPE_REVIEW_FIELDS_HOOK] ?? []
        );
        $this->assertContains([$this->integration, 'validate_comment'], $GLOBALS['bfas_test_hooks']['pre_comment_approved'] ?? []);
    }

    public function test_the_recipe_review_form_gets_a_honeypot_and_its_own_token(): void
    {
        $post = $this->recipe_review_post();
        $hp   = (new Honeypot($this->settings))->field_name('recipe_review');
        $this->assertArrayHasKey($hp, $post);
        $this->assertSame('', $post[$hp]);
        $this->assertArrayHasKey('bfas_tk_recipe_review', $post);
        $this->assertNotSame('', $post['bfas_tk_recipe_review']);
    }

    public function test_a_guest_recipe_review_with_its_token_passes(): void
    {
        $_POST = ['bfas_tk_recipe_review' => $this->aged_token('recipe_review')];
        $this->assertSame(0, $this->integration->validate_comment(0, $this->review()));
        $GLOBALS['bfas_test_rest'] = true;
        $this->assertSame(0, $this->integration->validate_comment(0, $this->review()));
    }

    public function test_a_recipe_review_checks_the_recipe_review_token_not_the_comment_one(): void
    {
        // A token for the classic comment form does not open the review form.
        $_POST = ['bfas_tk_wp_comment' => $this->aged_token('wp_comment')];
        $GLOBALS['bfas_test_rest'] = true;
        $this->assertInstanceOf(\WP_Error::class, $this->integration->validate_comment(0, $this->review()));
        // And a comment on the same recipe that is not a review still needs the comment token.
        $_POST = ['bfas_tk_recipe_review' => $this->aged_token('recipe_review')];
        $this->assertInstanceOf(\WP_Error::class, $this->integration->validate_comment(0, $this->review(10, 'comment')));
    }

    public function test_a_blocked_review_inside_rest_is_a_wp_error_with_403(): void
    {
        $GLOBALS['bfas_test_rest'] = true;
        $result = $this->integration->validate_comment(0, $this->review());
        $this->assertInstanceOf(\WP_Error::class, $result, 'no token');
        $this->assertSame('bfas_spam', $result->get_error_code());
        $this->assertSame(['status' => 403], $result->get_error_data());
        $this->assertStringContainsString('review was blocked', $result->get_error_message());

        $_POST = ['bfas_tk_recipe_review' => $this->aged_token('recipe_review'), (new Honeypot($this->settings))->field_name('recipe_review') => 'http://spam.example'];
        $this->assertInstanceOf(\WP_Error::class, $this->integration->validate_comment(0, $this->review()), 'honeypot filled');
    }

    public function test_outside_rest_a_block_still_stops_the_request(): void
    {
        $this->expectException(\BfasTestDied::class);
        $this->integration->validate_comment(0, $this->review(20, 'comment'));
    }

    public function test_a_classic_comment_with_its_token_still_passes(): void
    {
        $_POST = ['bfas_tk_wp_comment' => $this->aged_token('wp_comment')];
        $this->assertSame(1, $this->integration->validate_comment(1, $this->review(20, 'comment')));
    }

    // -------------------------------------------------------------------------
    // Core comments API (/wp/v2/comments) from a logged-out visitor
    // -------------------------------------------------------------------------

    /**
     * Run validate_comment() the way core's comments controller does: it fires
     * rest_preprocess_comment with the request, then wp_allow_comment() runs
     * pre_comment_approved.
     *
     * @param array<string, mixed> $params
     */
    private function core_api_comment(array $params, mixed $approved = 1, int $post_id = 20): mixed
    {
        $GLOBALS['bfas_test_rest'] = true;
        $comment = ['comment_post_ID' => $post_id, 'comment_type' => 'comment', 'comment_author_email' => 'sam@example.com'];
        $this->integration->remember_core_rest_comment($comment, new \WP_REST_Request($params));
        return $this->integration->validate_comment($approved, $comment);
    }

    public function test_register_hooks_the_core_comments_api_request(): void
    {
        $this->integration->register();
        $this->assertContains([$this->integration, 'remember_core_rest_comment'], $GLOBALS['bfas_test_hooks']['rest_preprocess_comment'] ?? []);
    }

    public function test_remembering_the_request_returns_the_comment_unchanged(): void
    {
        $comment = ['comment_content' => 'Hello'];
        $this->assertSame($comment, $this->integration->remember_core_rest_comment($comment, new \WP_REST_Request([])));
    }

    public function test_an_api_comment_without_a_token_is_held_for_moderation_not_refused(): void
    {
        $this->assertSame(0, $this->core_api_comment(['content' => 'Nice post']));
    }

    public function test_an_api_comment_core_already_marked_spam_stays_spam(): void
    {
        $this->assertSame('spam', $this->core_api_comment(['content' => 'x'], 'spam'));
    }

    public function test_an_api_comment_with_a_valid_token_keeps_cores_decision(): void
    {
        $this->assertSame(1, $this->core_api_comment(['bfas_tk_wp_comment' => $this->aged_token('wp_comment')]));
    }

    public function test_an_api_comment_with_a_bad_token_is_refused_with_403(): void
    {
        $result = $this->core_api_comment(['bfas_tk_wp_comment' => 'not-a-token']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bfas_spam', $result->get_error_code());
        $this->assertSame(['status' => 403], $result->get_error_data());
    }

    public function test_an_api_comment_that_fills_the_honeypot_is_refused(): void
    {
        $hp = (new Honeypot($this->settings))->field_name('wp_comment');
        $this->assertInstanceOf(\WP_Error::class, $this->core_api_comment([$hp => 'http://spam.example']));
    }

    public function test_an_api_comment_from_a_listed_spammer_is_refused(): void
    {
        $this->sfs = true;
        $GLOBALS['bfas_test_transients']['bfas_sfs_' . substr(md5('203.0.113.9sam@example.com'), 0, 12)] = 1;
        $this->assertInstanceOf(\WP_Error::class, $this->core_api_comment(['content' => 'x']));
    }

    public function test_an_api_comment_from_a_clean_address_with_sfs_on_is_held(): void
    {
        $this->sfs = true;
        $GLOBALS['bfas_test_transients']['bfas_sfs_' . substr(md5('203.0.113.9sam@example.com'), 0, 12)] = 0;
        $this->assertSame(0, $this->core_api_comment(['content' => 'x']));
    }

    public function test_with_the_time_trap_off_an_api_comment_without_a_token_is_not_held(): void
    {
        $this->timetrap = false;
        $this->assertSame(1, $this->core_api_comment(['content' => 'x']));
    }

    public function test_the_remembered_request_is_used_once(): void
    {
        $this->assertSame(0, $this->core_api_comment(['content' => 'x']));
        // The next comment in the same request is not a core API comment: a
        // recipe review without its token is still refused.
        $this->assertInstanceOf(\WP_Error::class, $this->integration->validate_comment(0, $this->review()));
    }

    public function test_a_logged_in_api_comment_still_uses_up_the_remembered_request(): void
    {
        $GLOBALS['bfas_test_logged_in'] = true;
        $this->assertSame(1, $this->core_api_comment(['content' => 'x']));
        $GLOBALS['bfas_test_logged_in'] = false;
        // Nothing left over: a recipe review without its token is refused, not held.
        $this->assertInstanceOf(\WP_Error::class, $this->integration->validate_comment(0, $this->review()));
    }

    public function test_outside_rest_a_remembered_request_is_ignored(): void
    {
        $this->integration->remember_core_rest_comment([], new \WP_REST_Request(['content' => 'x']));
        $GLOBALS['bfas_test_rest'] = false;
        $this->expectException(\BfasTestDied::class);
        $this->integration->validate_comment(1, $this->review(20, 'comment'));
    }
}
