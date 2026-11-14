<?php

/**
 * Contract of DependentTermOffer
 *
 * The public surface of DependentTermOffer that other components call, so they depend
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

/**
 * Contract of DependentTermOffer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DependentTermOfferInterface {

	/**
	 * The term events that move a date and therefore make an offer. A start,
	 * a completion and a notification move nothing, so they make none.
	 */
	public const MOVING_EVENTS = ['verleng', 'pauze'];

	/**
	 * Accept one offer: extend the waiting case's term by the days the task
	 * carries, for the reason the task carries, and complete the task.
	 *
	 * @param string      $taskId The engine task the handler accepted.
	 * @param string|null $actor  The acting user.
	 *
	 * @return array{refused?: string, event?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function accept(string $taskId, ?string $actor): array;

	/**
	 * Decline one offer: nothing moves, and the task is done.
	 *
	 * @param string      $taskId The engine task.
	 * @param string|null $actor  The acting user.
	 *
	 * @return bool True when the task was completed.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function decline(string $taskId, ?string $actor): bool;

	/**
	 * Offer a moved term to every case waiting on it.
	 *
	 * @param string $sourceCaseId The case whose term moved.
	 * @param string $sourceTitle  What that case is called, for the task text.
	 * @param int    $daysImpact   How many days it moved by.
	 *
	 * @return int How many offers were written.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function offer(string $sourceCaseId, string $sourceTitle, int $daysImpact): int;
}//end interface
