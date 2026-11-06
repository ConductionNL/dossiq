<?php

/**
 * What each dashboard page widget answers to the Nextcloud dashboard.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Dashboard;

use OCA\Dossiq\Dashboard\CasesOverviewWidget;
use OCA\Dossiq\Dashboard\DeadlineAlertsWidget;
use OCA\Dossiq\Dashboard\StalledCasesWidget;
use OCA\Dossiq\Dashboard\StartCaseWidget;
use OCA\Dossiq\Dashboard\TaskRemindersWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Each widget's id, title, order, icon and link, as the dashboard reads them.
 *
 * @covers \OCA\Dossiq\Dashboard\CasesOverviewWidget
 * @covers \OCA\Dossiq\Dashboard\DeadlineAlertsWidget
 * @covers \OCA\Dossiq\Dashboard\StalledCasesWidget
 * @covers \OCA\Dossiq\Dashboard\StartCaseWidget
 * @covers \OCA\Dossiq\Dashboard\TaskRemindersWidget
 * @covers \OCA\Dossiq\Dashboard\DashboardPageWidget
 */
class DashboardPageWidgetsGoldenTest extends TestCase {

	/**
	 * The widgets and what each answers: id, title, order, icon class.
	 *
	 * @return array<string, array{0: class-string<IWidget>, 1: array{string, string, int, string}}>
	 */
	public static function widgets(): array {
		return [
			'cases overview' => [CasesOverviewWidget::class, ['procest_cases_overview_widget', 'Cases overview', 10, 'icon-dossiq-widget']],
			'deadline alerts' => [DeadlineAlertsWidget::class, ['procest_deadline_alerts_widget', 'Deadline signals', 11, 'icon-dossiq-widget']],
			'stalled cases' => [StalledCasesWidget::class, ['procest_stalled_cases_widget', 'Stalled cases', 13, 'icon-dossiq-widget']],
			'start case' => [StartCaseWidget::class, ['procest_start_case_widget', 'Start case', 15, 'icon-dossiq-widget']],
			'task reminders' => [TaskRemindersWidget::class, ['procest_task_reminders_widget', 'Task reminders', 12, 'icon-dossiq-widget']],
		];
	}//end widgets()

	/**
	 * A widget answers its id, translated title, order and icon, and links to the app's dashboard page.
	 *
	 * @param class-string<IWidget>             $class    The widget class.
	 * @param array{string, string, int, string} $expected Id, title, order, icon class.
	 *
	 * @return void
	 *
	 * @dataProvider widgets
	 */
	public function testTheWidgetAnswersTheDashboard(string $class, array $expected): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$url = $this->createMock(originalClassName: IURLGenerator::class);
		$url->expects($this->once())->method('linkToRouteAbsolute')->with('dossiq.dashboard.page')->willReturn('https://x/apps/dossiq/');

		$widget = new $class($l10n, $url);

		self::assertSame(
			expected: [...$expected, 'https://x/apps/dossiq/'],
			actual: [$widget->getId(), $widget->getTitle(), $widget->getOrder(), $widget->getIconClass(), $widget->getUrl()]
		);
	}//end testTheWidgetAnswersTheDashboard()
}//end class
