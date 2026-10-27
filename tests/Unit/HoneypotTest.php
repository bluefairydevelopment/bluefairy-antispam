<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Traps\Honeypot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Honeypot::class)]
final class HoneypotTest extends TestCase
{
    /** @var Settings&MockObject */
    private Settings $settings;
    private Honeypot $honeypot;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('get')->willReturnMap([
            ['honeypot_enabled', true, true],
        ]);

        $this->honeypot = new Honeypot($this->settings);
    }

    // ------------------------------------------------------------------ //
    // field_name() — deterministic and pool-bound
    // ------------------------------------------------------------------ //

    private const FIELD_POOL = [
        'website2', 'address_confirm', 'phone_verify', 'url_field',
        'email_confirm2', 'contact_url', 'homepage_field', 'site_address',
    ];

    public function test_field_name_returns_value_from_pool(): void
    {
        $name = $this->honeypot->field_name('bfas_contact');

        $this->assertContains($name, self::FIELD_POOL);
    }

    public function test_field_name_is_deterministic_for_same_form_type(): void
    {
        $first  = $this->honeypot->field_name('bfas_contact');
        $second = $this->honeypot->field_name('bfas_contact');

        $this->assertSame($first, $second);
    }

    #[DataProvider('formTypeProvider')]
    public function test_field_name_matches_crc32_index(string $form_type): void
    {
        $expected_index = (crc32($form_type) & 0x7FFFFFFF) % 8;
        $expected_name  = self::FIELD_POOL[$expected_index];

        $this->assertSame($expected_name, $this->honeypot->field_name($form_type));
    }

    /** @return array<string, array{0: string}> */
    public static function formTypeProvider(): array
    {
        return [
            'comment' => ['comment'],
            'contact' => ['contact'],
            'woo'     => ['woocommerce_checkout'],
            'login'   => ['login'],
        ];
    }

    // ------------------------------------------------------------------ //
    // is_spam() — field filled
    // ------------------------------------------------------------------ //

    public function test_filled_honeypot_is_spam(): void
    {
        $field = $this->honeypot->field_name('comment');
        $post  = [$field => 'bot was here'];

        $this->assertTrue($this->honeypot->is_spam($post, 'comment'));
    }

    public function test_empty_string_honeypot_is_not_spam(): void
    {
        $field = $this->honeypot->field_name('comment');
        $post  = [$field => ''];

        $this->assertFalse($this->honeypot->is_spam($post, 'comment'));
    }

    public function test_whitespace_only_honeypot_is_not_spam(): void
    {
        $field = $this->honeypot->field_name('comment');
        $post  = [$field => '   '];

        $this->assertFalse($this->honeypot->is_spam($post, 'comment'));
    }

    public function test_absent_honeypot_field_is_not_spam(): void
    {
        // Field not present in POST at all — bots may strip the form to essentials.
        $this->assertFalse($this->honeypot->is_spam([], 'comment'));
    }

    // ------------------------------------------------------------------ //
    // is_spam() — disabled
    // ------------------------------------------------------------------ //

    public function test_honeypot_disabled_returns_not_spam_even_if_field_filled(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnMap([
            ['honeypot_enabled', true, false],
        ]);
        $honeypot = new Honeypot($settings);

        $field = $honeypot->field_name('comment');
        $post  = [$field => 'bot payload'];

        $this->assertFalse($honeypot->is_spam($post, 'comment'));
    }

    // ------------------------------------------------------------------ //
    // is_spam() — field from wrong form type doesn't trigger
    // ------------------------------------------------------------------ //

    public function test_different_form_type_field_is_not_spam(): void
    {
        // Get field for 'contact', fill it, but check 'newsletter' — the
        // two may use the same pool slot (collision) so find a non-colliding pair.
        $type_a = 'bfas_form_aaa';
        $type_b = 'bfas_form_bbb';

        // Skip if they resolve to the same pool index (collision — test would be vacuous).
        $index_a = (crc32($type_a) & 0x7FFFFFFF) % 8;
        $index_b = (crc32($type_b) & 0x7FFFFFFF) % 8;
        if ($index_a === $index_b) {
            $this->markTestSkipped('chosen types resolve to same honeypot field');
        }

        $field_a = $this->honeypot->field_name($type_a);
        $post    = [$field_a => 'filled'];

        // Checking against type_b — its honeypot field is different, so not spam.
        $this->assertFalse($this->honeypot->is_spam($post, $type_b));
    }

    public function test_an_array_in_the_honeypot_is_spam_without_a_warning(): void
    {
        $settings = $this->createMock(\BlueFairy\AntiSpam\Settings::class);
        $settings->method('get')->willReturn(true);
        $hp    = new \BlueFairy\AntiSpam\Traps\Honeypot($settings);
        $field = $hp->field_name('wp_comment');
        set_error_handler(static function (int $no, string $msg): never { throw new \ErrorException($msg, 0, $no); });
        try {
            $this->assertTrue($hp->is_spam([$field => ['']], 'wp_comment'));
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($hp->is_spam([$field => ''], 'wp_comment'));
    }
}
