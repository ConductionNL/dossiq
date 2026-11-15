<?php

/**
 * Deadline Alerts Dashboard Widget
 *
 * Displays cases approaching or past their processing deadline
 * in the Nextcloud Dashboard.
 *
 * @category Dashboard
 * @package  OCA\Dossiq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-4
 * @spec openspec/specs/signalering-widgets/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

/**
 * Dashboard widget showing deadline alerts for cases.
 *
 * @spec openspec/specs/signalering-widgets/spec.md
 */
class DeadlineAlertsWidget extends DashboardPageWidget {
	/**
	 * Get the unique identifier for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget identifier
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getId(): string {
		// FROZEN at the old app-id prefix — see CasesOverviewWidget::getId().
		return 'procest_deadline_alerts_widget';
	}//end getId()

	/**
	 * Get the display title for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget title
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Deadline signals');
	}//end getTitle()

	/**
	 * Get the display order for this widget.
	 *
	 * @inheritDoc
	 * @return int The widget order
	 *
	 * @spec openspec/specs/signalering-widgets/spec.md
	 */
	public function getOrder(): int {
		return 11;
	}//end getOrder()
}//end class
