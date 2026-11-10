<?php

/**
 * One item being scored: the day and the numbers it is scored against, and its parts.
 *
 * Built by `UrgencyScorer::scoreItem()` for one item and never handed out:
 * the deadline, priority and idle parts are written onto it in turn, and the
 * result is read off it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;

/**
 * The state of one urgency score while its parts are added.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-deterministic-urgency-scoring-mvp
 */
final class UrgencyScoreRun {
	/**
	 * Signed working days to the deadline, or null when the item has none.
	 *
	 * @var integer|null
	 */
	public ?int $daysUntilDeadline = null;

	/**
	 * The deadline tier, or null while the item has no deadline.
	 *
	 * @var string|null
	 */
	public ?string $deadlineTier = null;

	/**
	 * The deadline part of the score.
	 *
	 * @var float
	 */
	public float $deadlineComponent = 0.0;

	/**
	 * The priority part of the score.
	 *
	 * @var float
	 */
	public float $priorityComponent = 0.0;

	/**
	 * Days since the last activity, or null when the item has no reference date.
	 *
	 * @var integer|null
	 */
	public ?int $idleDays = null;

	/**
	 * The idle part of the score.
	 *
	 * @var float
	 */
	public float $idleComponent = 0.0;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $today   The day scored against, date only.
	 * @param UrgencyProfile    $profile The thresholds and weights.
	 */
	public function __construct(
		public readonly DateTimeImmutable $today,
		public readonly UrgencyProfile $profile,
	) {
	}//end __construct()
}//end class
