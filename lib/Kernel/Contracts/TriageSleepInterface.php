<?php

/**
 * Contract of TriageSleep
 *
 * The public surface of TriageSleep that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of TriageSleep.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TriageSleepInterface {

	/**
	 * The outcomes an item still waiting for a person can carry.
	 *
	 * @var array<int, string>
	 */
	public const SLEEPABLE_OUTCOMES = [IntakeLogInterface::OUTCOME_QUARANTINED, IntakeLogInterface::OUTCOME_INBOX];

	/**
	 * The queue with its sleeping items taken out.
	 *
	 * @param array<int, array<string, mixed>> $queue The triage queue.
	 *
	 * @return array<int, array<string, mixed>> The items a handler should see.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function awake(array $queue): array;

	/**
	 * Put one triage item to sleep until a date.
	 *
	 * @param string $entryId The intake log entry.
	 * @param string $until   The date it comes back, as YYYY-MM-DD.
	 * @param string $reason  Why nothing is done until then.
	 * @param string $actorId The uid of the person sleeping it.
	 *
	 * @return array{sleepUntil: string, sleepReason: string, sleptBy: string, sleptAt: string}
	 *
	 * @throws RefusedException When the date, the reason or the item refuses it.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function sleep(string $entryId, string $until, string $reason, string $actorId): array;

	/**
	 * Wake every item whose date has come.
	 *
	 * Waking clears the sleep and nothing else: the item returns to the queue
	 * it left, unassigned, because the person who slept it may be gone. The
	 * reason is kept, so the queue can still say why it was asleep.
	 *
	 * @return array<int, string> The ids of the items that woke.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function wakeDue(): array;
}//end interface
