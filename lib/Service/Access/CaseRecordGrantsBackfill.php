<?php

/**
 * Dossiq case record grants backfill
 *
 * Reconciles the private records of every case whose case type declares
 * reviewer access (case-reviewer-access, REQ-CRA-012): the upgrade runs it,
 * and the administrator route runs the same walk. It asks
 * CaseReviewerAccess::reconcile() per case, so there is one rule for who reads
 * a record, and it reads the cases a page at a time.
 *
 * It never reports more than it did. A case that threw, a record OpenRegister
 * refused and a walk that stopped at its bound are each counted and named in
 * the answer; `reconciled` is answered only when every case was walked and
 * nothing was refused.
 *
 * OpenRegister lets only a record's owner, its owning group or an
 * administrator change who reads it, and it writes a grant in the name of the
 * signed-in user. An upgrade has no signed-in user, so the walk runs as an
 * administrator account for its duration and puts the session back after. It
 * writes what the administrator route writes, and the answer names the
 * account.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks every case that keeps records private and reconciles their grants.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
 */
class CaseRecordGrantsBackfill {

	/**
	 * Cases per page.
	 */
	public const PAGE = 100;

	/**
	 * The most pages of cases one case type is walked over in one run.
	 */
	public const MAX_PAGES = 500;

	/**
	 * Core's administrators group.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * The cases of a case type, read regardless of the caller.
	 *
	 * @var CaseRecordLookup
	 */
	private readonly CaseRecordLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The case schema.
	 * @param CaseTypeStore      $caseTypes       Every case type.
	 * @param CaseTypeResolver   $resolver        The effective case type.
	 * @param CaseReviewerAccess $access          The reconcile of one case.
	 * @param IGroupManager      $groupManager    Who is an administrator.
	 * @param IUserSession       $userSession     The session the walk runs in.
	 * @param LoggerInterface    $logger          Names a case that could not be reconciled.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseReviewerAccess $access,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->lookup = new CaseRecordLookup(settingsService: $settingsService, caseTypes: $caseTypes, resolver: $resolver);
	}//end __construct()

	/**
	 * Reconcile every case of every case type that declares reviewer access.
	 *
	 * Safe to run again: a record that already carries the right grants is
	 * read and left alone.
	 *
	 * @return array{
	 *     status: string, actor: string, cases: int, granted: int, revoked: int, refused: int,
	 *     failed: list<string>, unfinished: list<string>
	 * }
	 * `status` is `reconciled` (every case walked, nothing refused), `incomplete` (a case in `failed`
	 * or a case type in `unfinished` was not fully reconciled), `unavailable` (no per-object sharing
	 * in this OpenRegister; nothing written) or `no-administrator` (no enabled administrator account
	 * to write the grants as; nothing written). `actor` is the account the grants were written as.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function run(): array {
		$answer = [
			'status' => 'reconciled',
			'actor' => '',
			'cases' => 0,
			'granted' => 0,
			'revoked' => 0,
			'refused' => 0,
			'failed' => [],
			'unfinished' => [],
		];
		$administrator = $this->administrator();
		if ($administrator === null) {
			$answer['status'] = 'no-administrator';
			return $answer;
		}

		$answer['actor'] = $administrator->getUID();
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($administrator);
		try {
			foreach ($this->declaringCaseTypes() as $caseTypeId) {
				$answer = $this->walk(caseTypeId: $caseTypeId, answer: $answer);
				if ($answer['status'] === 'unavailable') {
					return $answer;
				}
			}
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}

		if ($answer['failed'] !== [] || $answer['unfinished'] !== [] || $answer['refused'] > 0) {
			$answer['status'] = 'incomplete';
		}

		return $answer;
	}//end run()

	/**
	 * Reconcile the cases of one case type, a page at a time.
	 *
	 * @param string               $caseTypeId The case type.
	 * @param array<string, mixed> $answer     The tally so far.
	 *
	 * @return array<string, mixed> The tally.
	 */
	private function walk(string $caseTypeId, array $answer): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$read = $this->lookup->page(schema: $caseSchema, field: 'caseType', value: $caseTypeId, offset: ($page * self::PAGE), limit: self::PAGE);
			foreach ($read['rows'] as $case) {
				$answer = $this->one(caseId: $this->caseTypes->rowId(row: $case), answer: $answer);
				if ($answer['status'] === 'unavailable') {
					return $answer;
				}
			}

			if ($read['read'] < self::PAGE) {
				return $answer;
			}
		}

		$answer['unfinished'][] = $caseTypeId;
		$this->logger->error(
			'Dossiq reviewer access: case type {caseType} has more cases than one run walks; the rest were NOT reconciled',
			['caseType' => $caseTypeId, 'walked' => (self::MAX_PAGES * self::PAGE)]
		);

		return $answer;
	}//end walk()

	/**
	 * Reconcile one case and add what it did to the tally.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $answer The tally so far.
	 *
	 * @return array<string, mixed> The tally.
	 */
	private function one(string $caseId, array $answer): array {
		try {
			$result = $this->access->reconcile(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq reviewer access: case {case} could not be reconciled',
				['case' => $caseId, 'exception' => $e->getMessage()]
			);
			$answer['cases']++;
			$answer['failed'][] = $caseId;
			return $answer;
		}

		if ($result['status'] === 'unavailable') {
			$answer['status'] = 'unavailable';
			return $answer;
		}

		$answer['cases']++;
		$answer['granted'] += $result['granted'];
		$answer['revoked'] += $result['revoked'];
		$answer['refused'] += $result['refused'];
		if ($result['refused'] > 0) {
			$answer['failed'][] = $caseId;
		}

		return $answer;
	}//end one()

	/**
	 * The case types that keep records private to the case, in a fixed order.
	 *
	 * @return list<string>
	 */
	private function declaringCaseTypes(): array {
		$ids = [];
		foreach ($this->caseTypes->everyCaseType(scope: ['_rbac' => false, '_multitenancy' => false]) as $caseType) {
			$caseTypeId = $this->caseTypes->rowId(row: $caseType);
			if ($caseTypeId === '') {
				continue;
			}

			if ($this->access->declarationFor(caseType: $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId))['records'] !== []) {
				$ids[] = $caseTypeId;
			}
		}

		sort($ids);

		return $ids;
	}//end declaringCaseTypes()

	/**
	 * The account the grants are written as: the signed-in administrator, else the first enabled one.
	 *
	 * @return IUser|null Null when there is no enabled administrator account.
	 */
	private function administrator(): ?IUser {
		$current = $this->userSession->getUser();
		if ($current !== null && $this->groupManager->isAdmin($current->getUID()) === true) {
			return $current;
		}

		$enabled = [];
		foreach (($this->groupManager->get(self::ADMIN_GROUP)?->getUsers() ?? []) as $user) {
			if ($user->isEnabled() === true) {
				$enabled[$user->getUID()] = $user;
			}
		}

		ksort($enabled);

		return (array_values($enabled)[0] ?? null);
	}//end administrator()
}//end class
