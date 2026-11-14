<?php

/**
 * One person's queue while its sources are read.
 *
 * Built by `PersonalQueueService::forPerson()` and never handed out: each
 * source adds its closing sentence, its open items, or itself to the
 * unavailable list.
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
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * The state of one personal queue read.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-personal-work-queue-endpoint-mvp
 */
final class PersonalQueueRun {
	/**
	 * The open items read so far.
	 *
	 * @var array<int, QueueItem>
	 */
	public array $items = [];

	/**
	 * Source name to its label and closing sentence.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $closings = [];

	/**
	 * The sources that could not be read, with why.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $unavailable = [];

	/**
	 * Constructor.
	 *
	 * @param string $userId The person whose queue this is.
	 * @param string $today  Today, as Y-m-d, for the groups hidden today.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $today,
	) {
	}//end __construct()
}//end class
