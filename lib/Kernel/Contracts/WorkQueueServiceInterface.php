<?php

/**
 * Contract of WorkQueueService
 *
 * The public surface of WorkQueueService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of WorkQueueService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkQueueServiceInterface {

	/**
	 * Compute the urgency-scored work queue for one user.
	 *
	 * Aggregates the user's open cases (assignee match, endDate empty) and
	 * open tasks (assignee match, non-terminal status), scores every item,
	 * and returns them sorted by score descending (most urgent first).
	 *
	 * Open cases the user covers for an absent colleague ({@see Substitution\CoveredWork})
	 * join the ranking when given, scored the same way and never twice; each case item carries
	 * `coveredFor` (`null` for the user's own), on the item and on its row.
	 *
	 * @param string $userId The Nextcloud user id to scope to.
	 * @param DateTimeImmutable|null $now Optional "now" override for testing.
	 * @param array<int, array<string, mixed>> $covered Open covered cases, each with `coveredFor`; already read-checked.
	 *
	 * @return array<int, array<string, mixed>> Scored, sorted queue items.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function computeQueue(string $userId, ?DateTimeImmutable $now = null, array $covered = []): array;

	/**
	 * Compute per-handler open-case counts across all cases.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int}> Handlers sorted by count descending.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function computeWorkload(): array;
}//end interface
