<?php

/**
 * Contract of DeadlinePauseService
 *
 * The public surface of DeadlinePauseService that other components call, so they depend
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
use OCA\Dossiq\Exception\RefusedException;
use RuntimeException;

/**
 * Contract of DeadlinePauseService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeadlinePauseServiceInterface {

	/**
	 * Register a pauze on a TermijnInstance.
	 *
	 * Extends einddatumActueel by `duurDagen`, sets status=gepauzeerd,
	 * records a `pauze` event with dagenImpact=+duurDagen, and stores
	 * the pause deadline for the daily scan to watch.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param int $durationDays Pause days requested.
	 * @param string $rationale Reason.
	 * @param string $documentLink Document link (e.g. hersteltermijnbrief).
	 * @param string $pauseReason The key of the declared reason this pause is registered
	 *        under. The rule; `$rationale` stays the note beside it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When instance missing or duurDagen <= 0.
	 * @throws RefusedException When the case type forbids the suspension, forbids it this
	 *         long, or does not declare the reason named.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function registerPauze(
		string $termInstanceId,
		int $durationDays,
		string $rationale,
		string $documentLink = '',
		string $pauseReason = '',
	): array;

	/**
	 * Resume a term whose pause ran out without an answer (REQ-TERM-080).
	 *
	 * Every pause day was used, so the end date the pause pushed out stands:
	 * nothing is pulled back, and the administered calendar still decides the
	 * day it lands on. The engine timer resumes, the pause is cleared exactly
	 * as a handler's resume clears it, and a `hervat` event records that the
	 * term resumed because the pause ended. The event carries the notice, so
	 * the notification rule on the event schema tells the handler the case and
	 * the new end date.
	 *
	 * @param string                 $termInstanceId Instance id.
	 * @param array<string, string>  $notice         Who to tell: `notify` (the
	 *        handler's user id) and `caseTitle`. The new end date is added here.
	 * @param DateTimeImmutable|null $moment         When the pause ran out (default now).
	 *
	 * @return array<string, mixed> The resumed instance.
	 *
	 * @throws RuntimeException When the instance is missing or no longer paused.
	 *
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
	 */
	public function resumeAfterExpiry(string $termInstanceId, array $notice = [], ?DateTimeImmutable $moment = null): array;

	/**
	 * Resume after pauze with the aanvulling-datum.
	 *
	 * Computes consumed vs. unconsumed pause days; adds only the
	 * unconsumed portion to einddatumActueel; sets status=lopend and
	 * records the `hervat` event.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param DateTimeImmutable|null $aanvullingDatum When aanvulling received (default now).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When instance missing or not paused.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function resumeAfterPauze(string $termInstanceId, ?DateTimeImmutable $aanvullingDatum = null): array;
}//end interface
