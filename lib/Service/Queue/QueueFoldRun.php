<?php

/**
 * One fold of the open cases into the team queue's figures.
 *
 * Built by `QueueFigures::fold()` and never handed out: each case is counted
 * under its type, and a case without a handler adds to the waiting figures
 * and may become the oldest one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;

/**
 * The state of one queue fold.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
 */
final class QueueFoldRun {
	/**
	 * Today, Y-m-d.
	 *
	 * @var string
	 */
	public readonly string $todayText;

	/**
	 * Seven days from today, Y-m-d; set by the fold that builds the run.
	 *
	 * @var string
	 */
	public string $weekEnd = '';

	/**
	 * The figures so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $figures = [
		'queueCount' => 0,
		'queueNewToday' => 0,
		'queueDueSoon' => 0,
		'queueOldestDays' => 0,
		'queueOldestType' => '',
		'queueOldestDueDays' => null,
	];

	/**
	 * The start date of the oldest waiting case so far, or null before one.
	 *
	 * @var string|null
	 */
	public ?string $oldestStart = null;

	/**
	 * Open and waiting counts by case type.
	 *
	 * @var array<string, array{open: int, waiting: int}>
	 */
	public array $byType = [];

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable     $today      Today, at midnight.
	 * @param array<string, string> $typeTitles Case type titles by id.
	 */
	public function __construct(
		public readonly DateTimeImmutable $today,
		public readonly array $typeTitles,
	) {
		$this->todayText = $today->format('Y-m-d');
	}//end __construct()
}//end class
