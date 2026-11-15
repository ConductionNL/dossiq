<?php

/**
 * Contract of CaseFlowActions
 *
 * The public surface of CaseFlowActions that other components call, so they depend
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
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use RuntimeException;

/**
 * Contract of CaseFlowActions.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseFlowActionsInterface {

	/**
	 * Plan a follow-up case for a later date.
	 *
	 * Writes ONE scheduled flow: a schedule trigger pinned to the chosen date
	 * feeding dossiq's `createSubCase` node. The trigger carries an explicit
	 * `runAs` — OpenRegister's TriggerScheduleNode refuses to save without one
	 * and does NOT fall back to the flow's owner, because authoring a flow is
	 * not consent to unattended execution as its author.
	 *
	 * A recurrence makes it a SERIES: the same flow, cron fields that come round
	 * again, and an end the sweep enforces. The recurrence is a token from a
	 * fixed list, never a cron expression somebody typed — a five-field
	 * expression is a language, and asking a case handler to write one is how a
	 * yearly permit check ends up firing every day in January.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param string $date The date it is due, as `Y-m-d`.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as, and who planned it.
	 * @param string $recurrence `none`, `monthly`, `quarterly`, `halfYearly` or `yearly`.
	 * @param string $until The last date the series may fire on, as `Y-m-d`, or the empty string.
	 * @param integer $count How many occurrences the series runs for, or 0.
	 *
	 * @return array{id: string, title: string, date: string, caseType: string, recurrence: string, until: string, count: int} The planned follow-up.
	 *
	 * @throws RuntimeException `flows_unavailable`, `invalid_date`, `invalid_recurrence`, `invalid_end`, `plan_failed`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function plan(
		string $caseId,
		string $caseTypeId,
		string $date,
		string $title,
		string $uid,
		string $recurrence = PlannedFollowUpDocument::RECURRENCE_NONE,
		string $until = '',
		int $count = 0,
	): array;

	/**
	 * The follow-ups planned for this case that are still to come.
	 *
	 * A SINGLE follow-up drops off the list the moment it has fired: the case
	 * it created is an ordinary related case from then on, and `lastRunAt` is
	 * the one table read that says so. A SERIES stays, because a series that
	 * has fired twice still has its next occurrence in front of it, and each
	 * row carries the cases it has already opened so the two are read together.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{
	 *     results: array<int, array{
	 *         id: string,
	 *         title: string,
	 *         date: string,
	 *         caseType: string,
	 *         recurrence: string,
	 *         until: string,
	 *         count: int,
	 *         occurrences: array<int, array{id: string, title: string}>
	 *     }>,
	 *     total: int
	 * } The planned rows.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function planned(string $caseId): array;

	/**
	 * Switch off every planned follow-up that has nothing left to do.
	 *
	 * @param DateTimeImmutable|null $today The day the sweep is running on.
	 *
	 * @return integer How many flows were switched off.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function retireSpent(?DateTimeImmutable $today = null): int;

	/**
	 * The flows this case's type allows a handler to start.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{results: array<int, array{id: string, title: string, description: string}>, total: int} The startable flows.
	 *
	 * @throws RuntimeException `case_not_found` when the case or its type does not resolve.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function startableFlows(string $caseId): array;

	/**
	 * Stop a series, leaving the cases it has already opened alone.
	 *
	 * The gesture stays on this class because the Actions menu and the
	 * controller reach for it here, next to plan. The bookkeeping it needs
	 * lives on {@see PlannedSeriesLedger}.
	 *
	 * @param string $caseId The case the series belongs to.
	 * @param string $flowId The series flow's uuid.
	 * @param string $uid Who stopped it.
	 *
	 * @return array{id: string, stopped: bool} The stopped series.
	 *
	 * @throws RuntimeException `flows_unavailable`, `case_not_found`, `stop_failed`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function stopSeries(string $caseId, string $flowId, string $uid): array;
}//end interface
