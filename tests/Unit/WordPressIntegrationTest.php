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
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'honeypot_enabled', 'timetrap_enabled' => true,
                'min_time_seconds' => 3,
                'sfs_enabled' => false,
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
        unset($_SERVER['REMOTE_ADDR'], $GLOBALS['bfas_test_hooks'], $GLOBALS['bfas_test_posts'], $GLOBALS['bfas_test_rest']);
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
}
