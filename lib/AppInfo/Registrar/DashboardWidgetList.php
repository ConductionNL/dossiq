<?php

/**
 * The dashboard widgets dossiq hands to the AppHost engine.
 *
 * Named here, one class each, so the registrar that passes them on stays small.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Dashboard\CasesOverviewWidget;
use OCA\Dossiq\Dashboard\DeadlineAlertsWidget;
use OCA\Dossiq\Dashboard\MyOpenWorkWidget;
use OCA\Dossiq\Dashboard\MyTasksWidget;
use OCA\Dossiq\Dashboard\OverdueCasesWidget;
use OCA\Dossiq\Dashboard\StalledCasesWidget;
use OCA\Dossiq\Dashboard\StartCaseWidget;
use OCA\Dossiq\Dashboard\TaskRemindersWidget;

/**
 * The dashboard widget classes dossiq registers.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DashboardWidgetList {
	/**
	 * The widget classes, in the order the dashboard offers them.
	 *
	 * @return list<class-string> The widget classes.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function classes(): array {
		return [
			CasesOverviewWidget::class,
			MyTasksWidget::class,
			MyOpenWorkWidget::class,
			OverdueCasesWidget::class,
			DeadlineAlertsWidget::class,
			TaskRemindersWidget::class,
			StalledCasesWidget::class,
			StartCaseWidget::class,
		];
	}//end classes()
}//end class
