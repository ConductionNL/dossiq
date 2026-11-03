<?php

/**
 * Start Case Dashboard Widget
 *
 * Displays available case types for quick case creation from the Nextcloud Dashboard.
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
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-5
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

/**
 * Dashboard widget showing available case types for quick case creation.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class StartCaseWidget extends DashboardPageWidget {
	/**
	 * Get the unique identifier for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget identifier
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getId(): string {
		// FROZEN at the old app-id prefix — see CasesOverviewWidget::getId().
		return 'procest_start_case_widget';
	}//end getId()

	/**
	 * Get the display title for this widget.
	 *
	 * @inheritDoc
	 * @return string The widget title
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Start case');
	}//end getTitle()

	/**
	 * Get the display order for this widget.
	 *
	 * @inheritDoc
	 * @return int The widget order
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getOrder(): int {
		return 15;
	}//end getOrder()
}//end class
