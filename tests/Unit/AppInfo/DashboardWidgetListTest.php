<?php

/**
 * The widget list the AppHost registrar passes on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\DashboardWidgetList;
use OCA\Dossiq\Dashboard\MyOpenWorkWidget;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\DashboardWidgetList
 */
class DashboardWidgetListTest extends TestCase {
	public function testEveryListedWidgetClassExistsOnce(): void {
		$classes = (new DashboardWidgetList())->classes();

		self::assertContains(MyOpenWorkWidget::class, $classes);
		// One Mijn werk widget replaces My tasks and Overdue cases (board DqNextcloudDashboard).
		self::assertNotContains(needle: 'OCA\\Dossiq\\Dashboard\\MyTasksWidget', haystack: $classes);
		self::assertNotContains(needle: 'OCA\\Dossiq\\Dashboard\\OverdueCasesWidget', haystack: $classes);
		self::assertSame($classes, array_values(array_unique($classes)));
		foreach ($classes as $class) {
			self::assertTrue(class_exists($class), $class);
		}
	}
}
