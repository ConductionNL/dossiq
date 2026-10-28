<?php

/**
 * Who may write on a family plan: whoever may change the plan's case.
 *
 * A gezinsplan points at its case (`caseId`), a goal at its plan or at a case
 * directly (`case`), an intervention at its plan. Every write on any of them
 * asks {@see CaseAccessGuard::hasCaseMutationAccess()} for that case before
 * the store is written. A write naming an existing goal or intervention by id
 * also needs the right to change that record's case, so an id taken from
 * another family's plan is not rewritten through a plan the caller may change.
 * A plan without a case has nothing to check and is not written.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\SociaalDomein
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
 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IUserSession;

/**
 * The access check every family plan write runs first.
 *
 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
 */
class FamilyPlanWriteAccess {

	/**
	 * Constructor.
	 *
	 * @param SociaalDomeinStore $store           The one reader of these schemas.
	 * @param CaseAccessGuard    $caseAccessGuard Per-case authorization (fails closed).
	 * @param IUserSession       $userSession     The session.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SociaalDomeinStore $store,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The signed-in user's id, or '' without a session.
	 *
	 * @return string The uid.
	 *
	 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function currentUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * The status a write on a plan answers before it runs: 200 when it may run,
	 * 404 for a plan that is not there, else the status of the case check.
	 *
	 * @param string $planId       The gezinsplan uuid from the route.
	 * @param string $recordSchema The schema of the record the write names by id, or ''.
	 * @param string $recordId     The id of the record the write names, or ''.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function planWrite(string $planId, string $recordSchema = '', string $recordId = ''): int {
		$plan = $this->store->read(schema: CasePlanReview::SCHEMA, id: $planId);
		if ($plan === null) {
			return Http::STATUS_NOT_FOUND;
		}

		$status = $this->caseWrite(caseId: trim((string)($plan['caseId'] ?? '')));
		if ($status !== Http::STATUS_OK || $recordSchema === '' || $recordId === '') {
			return $status;
		}

		$record = $this->store->read(schema: $recordSchema, id: $recordId);
		if ($record === null) {
			return Http::STATUS_OK;
		}

		return $this->caseWrite(caseId: $this->caseOf(record: $record));
	}//end planWrite()

	/**
	 * The status closing a goal answers before it runs: 404 for a goal that is
	 * not there, else the status of the check on the goal's case.
	 *
	 * @param string $goalId The goal uuid.
	 *
	 * @return int The HTTP status.
	 *
	 * @throws RefusedException When the store cannot be read.
	 *
	 * @spec openspec/changes/family-plan-writes-check-the-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-write-on-a-family-plan-needs-the-right-to-change-its-case
	 */
	public function goalWrite(string $goalId): int {
		$goal = $this->store->read(schema: CasePlanGoals::SCHEMA, id: $goalId);
		if ($goal === null) {
			return Http::STATUS_NOT_FOUND;
		}

		return $this->caseWrite(caseId: $this->caseOf(record: $goal));
	}//end goalWrite()

	/**
	 * The case a goal or intervention belongs to: its own `case`, else its plan's `caseId`.
	 *
	 * @param array<string, mixed> $record The goal or intervention.
	 *
	 * @return string The case uuid, or '' when none is found.
	 *
	 * @throws RefusedException When the store cannot be read.
	 */
	private function caseOf(array $record): string {
		$caseId = trim((string)($record['case'] ?? ''));
		if ($caseId !== '') {
			return $caseId;
		}

		$plan = $this->store->read(schema: CasePlanReview::SCHEMA, id: trim((string)($record['plan'] ?? '')));

		return trim((string)($plan['caseId'] ?? ''));
	}//end caseOf()

	/**
	 * 401 without a session, 403 when the caller may not change the case or
	 * there is no case to check, 200 when the write may run.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return int The HTTP status.
	 */
	private function caseWrite(string $caseId): int {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return Http::STATUS_UNAUTHORIZED;
		}

		if ($caseId === '' || $this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return Http::STATUS_FORBIDDEN;
		}

		return Http::STATUS_OK;
	}//end caseWrite()
}//end class
