<?php

/**
 * Signalering Widgets Unit Tests
 *
 * Tests for the Procest signalering (alerting) dashboard widgets.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Dashboard;

use OCA\Procest\Dashboard\CasesOverviewWidget;
use OCA\Procest\Dashboard\DeadlineAlertsWidget;
use OCA\Procest\Dashboard\OverdueCasesWidget;
use OCA\Procest\Dashboard\StalledCasesWidget;
use OCA\Procest\Dashboard\TaskRemindersWidget;
use OCA\Procest\Dashboard\MyTasksWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the signalering dashboard widgets.
 *
 * @covers \OCA\Procest\Dashboard\CasesOverviewWidget
 * @covers \OCA\Procest\Dashboard\DeadlineAlertsWidget
 * @covers \OCA\Procest\Dashboard\OverdueCasesWidget
 * @covers \OCA\Procest\Dashboard\StalledCasesWidget
 */
class SignaleringWidgetsTest extends TestCase
{

    /**
     * The mocked L10N service.
     *
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private IL10N $l10n;

    /**
     * The mocked URL generator.
     *
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private IURLGenerator $url;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->l10n = $this->createMock(IL10N::class);
        $this->url  = $this->createMock(IURLGenerator::class);

        $this->l10n->method('t')->willReturnArgument(0);
        $this->url->method('linkToRouteAbsolute')->willReturn('https://example.com/apps/procest/dashboard');

    }//end setUp()


    /**
     * Test CasesOverviewWidget returns the correct widget ID.
     *
     * @return void
     */
    public function testCasesOverviewWidgetId(): void
    {
        $widget = new CasesOverviewWidget($this->l10n, $this->url);
        $this->assertSame('procest_cases_overview_widget', $widget->getId());

    }//end testCasesOverviewWidgetId()


    /**
     * Test CasesOverviewWidget returns a display title.
     *
     * @return void
     */
    public function testCasesOverviewWidgetTitle(): void
    {
        $widget = new CasesOverviewWidget($this->l10n, $this->url);
        $this->assertNotEmpty($widget->getTitle());

    }//end testCasesOverviewWidgetTitle()


    /**
     * Test CasesOverviewWidget returns a URL to the dashboard.
     *
     * @return void
     */
    public function testCasesOverviewWidgetUrl(): void
    {
        $widget = new CasesOverviewWidget($this->l10n, $this->url);
        $url    = $widget->getUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString('procest', $url);

    }//end testCasesOverviewWidgetUrl()


    /**
     * Test DeadlineAlertsWidget returns the correct widget ID.
     *
     * @return void
     */
    public function testDeadlineAlertsWidgetId(): void
    {
        $widget = new DeadlineAlertsWidget($this->l10n, $this->url);
        $this->assertSame('procest_deadline_alerts_widget', $widget->getId());

    }//end testDeadlineAlertsWidgetId()


    /**
     * Test DeadlineAlertsWidget returns a higher order than CasesOverviewWidget.
     *
     * @return void
     */
    public function testDeadlineAlertsWidgetOrder(): void
    {
        $casesWidget    = new CasesOverviewWidget($this->l10n, $this->url);
        $deadlineWidget = new DeadlineAlertsWidget($this->l10n, $this->url);

        $this->assertGreaterThan($casesWidget->getOrder(), $deadlineWidget->getOrder());

    }//end testDeadlineAlertsWidgetOrder()


    /**
     * Test DeadlineAlertsWidget provides an icon class.
     *
     * @return void
     */
    public function testDeadlineAlertsWidgetIconClass(): void
    {
        $widget = new DeadlineAlertsWidget($this->l10n, $this->url);
        $this->assertNotEmpty($widget->getIconClass());

    }//end testDeadlineAlertsWidgetIconClass()


    /**
     * Test OverdueCasesWidget returns the correct widget ID.
     *
     * @return void
     */
    public function testOverdueCasesWidgetId(): void
    {
        $widget = new OverdueCasesWidget($this->l10n, $this->url);
        $this->assertSame('procest_overdue_cases_widget', $widget->getId());

    }//end testOverdueCasesWidgetId()


    /**
     * Test StalledCasesWidget returns the correct widget ID.
     *
     * @return void
     */
    public function testStalledCasesWidgetId(): void
    {
        $widget = new StalledCasesWidget($this->l10n, $this->url);
        $this->assertSame('procest_stalled_cases_widget', $widget->getId());

    }//end testStalledCasesWidgetId()


    /**
     * Test TaskRemindersWidget returns the correct widget ID.
     *
     * @return void
     */
    public function testTaskRemindersWidgetId(): void
    {
        $widget = new TaskRemindersWidget($this->l10n, $this->url);
        $this->assertSame('procest_task_reminders_widget', $widget->getId());

    }//end testTaskRemindersWidgetId()


    /**
     * Test all signalering widgets link back to the dashboard route.
     *
     * @return void
     */
    public function testAllWidgetsLinkToDashboard(): void
    {
        $widgets = [
            new CasesOverviewWidget($this->l10n, $this->url),
            new DeadlineAlertsWidget($this->l10n, $this->url),
            new OverdueCasesWidget($this->l10n, $this->url),
            new StalledCasesWidget($this->l10n, $this->url),
            new TaskRemindersWidget($this->l10n, $this->url),
            new MyTasksWidget($this->l10n, $this->url),
        ];

        foreach ($widgets as $widget) {
            $url = $widget->getUrl();
            $this->assertNotNull($url, 'Widget '.$widget->getId().' should return a URL');
        }

    }//end testAllWidgetsLinkToDashboard()


    /**
     * Test all signalering widgets have unique IDs.
     *
     * @return void
     */
    public function testAllWidgetsHaveUniqueIds(): void
    {
        $widgets = [
            new CasesOverviewWidget($this->l10n, $this->url),
            new DeadlineAlertsWidget($this->l10n, $this->url),
            new OverdueCasesWidget($this->l10n, $this->url),
            new StalledCasesWidget($this->l10n, $this->url),
            new TaskRemindersWidget($this->l10n, $this->url),
            new MyTasksWidget($this->l10n, $this->url),
        ];

        $ids = array_map(
            function ($w) {
                return $w->getId();
            },
            $widgets
        );

        $this->assertSame(count($ids), count(array_unique($ids)), 'Each widget must have a unique ID');

    }//end testAllWidgetsHaveUniqueIds()


    /**
     * Test all signalering widgets have non-empty titles.
     *
     * @return void
     */
    public function testAllWidgetsHaveNonEmptyTitles(): void
    {
        $widgets = [
            new CasesOverviewWidget($this->l10n, $this->url),
            new DeadlineAlertsWidget($this->l10n, $this->url),
            new OverdueCasesWidget($this->l10n, $this->url),
            new StalledCasesWidget($this->l10n, $this->url),
            new TaskRemindersWidget($this->l10n, $this->url),
            new MyTasksWidget($this->l10n, $this->url),
        ];

        foreach ($widgets as $widget) {
            $this->assertNotEmpty($widget->getTitle(), 'Widget '.$widget->getId().' must have a non-empty title');
        }

    }//end testAllWidgetsHaveNonEmptyTitles()


}//end class
