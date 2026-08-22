<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Admin\DashboardWidget;
use BlueFairy\AntiSpam\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DashboardWidget::class)]
final class DashboardWidgetEnqueueTest extends TestCase
{
    /** @var Logger&MockObject */
    private Logger $logger;

    private DashboardWidget $widget;

    protected function setUp(): void
    {
        if (! defined('BFAS_URL')) {
            define('BFAS_URL', 'https://example.test/wp-content/plugins/bluefairy-antispam/');
        }
        if (! defined('BFAS_VERSION')) {
            define('BFAS_VERSION', 'test');
        }

        $this->logger = $this->createMock(Logger::class);
        $this->widget = new DashboardWidget($this->logger);

        $GLOBALS['bfas_test_enqueued_styles'] = [];
    }

    public function test_enqueues_on_dashboard_hook(): void
    {
        $this->widget->enqueue_assets('index.php');

        $enqueued = $GLOBALS['bfas_test_enqueued_styles'];

        $this->assertCount(1, $enqueued, 'Exactly one stylesheet must be enqueued on the dashboard.');
        $this->assertSame('bfas-dashboard-widget', $enqueued[0]['handle']);
        $this->assertStringEndsWith('assets/admin/css/dashboard-widget.css', $enqueued[0]['src']);
        $this->assertSame(BFAS_VERSION, $enqueued[0]['ver']);
    }

    public function test_does_not_enqueue_on_other_hook(): void
    {
        $this->widget->enqueue_assets('edit.php');

        $this->assertSame(
            [],
            $GLOBALS['bfas_test_enqueued_styles'],
            'Widget stylesheet must not leak to other admin screens.'
        );
    }
}
