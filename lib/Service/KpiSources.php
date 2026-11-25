<?php

/**
 * Where the KPI figures are read from: the engine task inbox and the queue figures, with the text and day helpers they use
 * and the measures over closed cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Dashboard\ClosedCaseMeasures;
use OCA\Dossiq\Service\Queue\QueueFigures;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Where the KPI figures are read from: the engine task inbox and the queue figures, with the text and day helpers they use
 * and the measures over closed cases.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */
final class KpiSources {
	/**
	 * The average duration and the deadline compliance of closed cases, counted with `$days`.
	 *
	 * @var ClosedCaseMeasures
	 */
	public readonly ClosedCaseMeasures $measures;

	/**
	 * Constructor.
	 *
	 * @param EngineTaskInbox $engineTasks  The engine's task counter.
	 * @param QueueFigures    $queueFigures Folds the team queue's figures.
	 * @param LanguageMapText $text         Reads a translatable case type title as text.
	 * @param DayCount        $days         The one place days between two dates are counted.
	 */
	public function __construct(
		public readonly EngineTaskInbox $engineTasks,
		public readonly QueueFigures $queueFigures,
		public readonly LanguageMapText $text = new LanguageMapText(),
		public readonly DayCount $days = new DayCount(),
	) {
		$this->measures = new ClosedCaseMeasures(days: $days);
	}//end __construct()
}//end class
