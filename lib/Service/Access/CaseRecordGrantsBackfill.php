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
 * OpenRegister lets a record's owner, its owning group, an administrator and
 * a holder of the organisation's special right `grant_manage` change who reads
 * it, and it writes a grant in the name of the signed-in user (decision 234).
 * The upgrade has no signed-in user, so it runs as dossiq's background service
 * account (decision 222): it first gives the service group that special right,
 * narrowed to this register and to the record schemas the case types declare
 * private, and then walks inside BackgroundServiceAccount::runAs(). Without a
 * usable service account it writes nothing and says so; it never borrows an
 * administrator. The administrator route walks as the administrator who calls
 * it. The answer names the account either way.
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
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
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
	 * OpenRegister's reader and writer of an organisation's special rights.
	 */
	public const SPECIAL_RIGHTS = 'OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights';

	/**
	 * The special right that lets its holder manage the grants on a record it does not own.
	 */
	public const GRANT_MANAGE = 'grant_manage';

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
	 * @param CaseReviewerAccess       $access          The reconcile of one case.
	 * @param BackgroundServiceAccount $serviceAccount  The account the upgrade writes the grants as.
	 * @param IUserSession             $userSession     Who calls the administrator route.
	 * @param ContainerInterface       $container       Resolves OpenRegister's special rights by name.
	 * @param LoggerInterface          $logger          Names a case that could not be reconciled.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseReviewerAccess $access,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
		$this->lookup = new CaseRecordLookup(settingsService: $settingsService, caseTypes: $caseTypes, resolver: $resolver);
	}//end __construct()

	/**
	 * Reconcile every case of every case type that declares reviewer access, as the background service account.
	 *
	 * The upgrade's walk. Safe to run again: a record that already carries the
	 * right grants is read and left alone, and the special right is written
	 * only where it is missing.
	 *
	 * @return array{
	 *     status: string, actor: string, cases: int, granted: int, revoked: int, refused: int,
	 *     failed: list<string>, unfinished: list<string>
	 * }
	 * `status` is `reconciled` (every case walked, nothing refused), `incomplete` (a case in `failed`
	 * or a case type in `unfinished` was not fully reconciled), `unavailable` (no per-object sharing
	 * in this OpenRegister; nothing written), `no-service-account` (no usable background service
	 * account; nothing written) or `no-grant-right` (OpenRegister could not give the service group
	 * the special right `grant_manage`; nothing written). `actor` is the account the grants were
	 * written as.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-on-upgrade-req-cra-012
	 */
	public function run(): array {
		$answer = $this->blank();
		try {
			$answer['actor'] = $this->serviceAccount->require()->getUID();
		} catch (ServiceAccountUnavailableException $e) {
			$answer['status'] = 'no-service-account';
			$this->logger->error(
				'Dossiq reviewer access: NOT reconciled, there is no usable background service account; no administrator is used instead',
				['reason' => $e->getMessage()]
			);
			return $answer;
		}

		$caseTypes = $this->declaringCaseTypes();
		if ($this->giveTheGrantRight(caseTypes: $caseTypes) === false) {
			$answer['status'] = 'no-grant-right';
			return $answer;
		}

		return $this->serviceAccount->runAs(fn (): array => $this->walkAll(caseTypes: $caseTypes, answer: $answer));
	}//end run()

	/**
	 * Reconcile every declaring case as the signed-in caller: the administrator route.
	 *
	 * The route checks that the caller is an administrator; OpenRegister
	 * admits an administrator to every record's grants, so no special right is
	 * needed or written here.
	 *
	 * @return array<string, mixed> The same answer as run(), with the caller as `actor`.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
	 */
	public function runAsCaller(): array {
		$answer = $this->blank();
		$answer['actor'] = (string)$this->userSession->getUser()?->getUID();

		return $this->walkAll(caseTypes: $this->declaringCaseTypes(), answer: $answer);
	}//end runAsCaller()

	/**
	 * An answer before anything was done.
	 *
	 * @return array<string, mixed>
	 */
	private function blank(): array {
		return [
			'status' => 'reconciled',
			'actor' => '',
			'cases' => 0,
			'granted' => 0,
			'revoked' => 0,
			'refused' => 0,
			'failed' => [],
			'unfinished' => [],
		];
	}//end blank()

	/**
	 * Walk the cases of the given case types and settle the status.
	 *
	 * @param list<string>         $caseTypes The declaring case types.
	 * @param array<string, mixed> $answer    The answer so far.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function walkAll(array $caseTypes, array $answer): array {
		foreach ($caseTypes as $caseTypeId) {
			$answer = $this->walk(caseTypeId: $caseTypeId, answer: $answer);
			if ($answer['status'] === 'unavailable') {
				return $answer;
			}
		}

		if ($answer['failed'] !== [] || $answer['unfinished'] !== [] || $answer['refused'] > 0) {
			$answer['status'] = 'incomplete';
		}

		return $answer;
	}//end walkAll()

	/**
	 * Give the background service group the special right on the private record schemas, in every organisation.
	 *
	 * Narrowed to this register and to the schemas the case types declare
	 * private, read from the declarations rather than listed here, so a case
	 * type that keeps another record private is covered on the next upgrade.
	 *
	 * @param list<string> $caseTypes The declaring case types.
	 *
	 * @return bool False when OpenRegister has no special rights or refused the write.
	 */
	private function giveTheGrantRight(array $caseTypes): bool {
		$schemas = [];
		foreach ($caseTypes as $caseTypeId) {
			foreach ($this->access->declarationFor(caseType: $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId))['records'] as $record) {
				$slug = $this->settingsService->getConfigValue($record['schemaKey']);
				if ($slug !== '') {
					$schemas[$slug] = true;
				}
			}
		}

		$schemas = array_keys($schemas);
		sort($schemas);
		if ($schemas === []) {
			return true;
		}

		try {
			$rights = $this->container->get(self::SPECIAL_RIGHTS);
			$rights->giveInEveryOrganisation(
				right: self::GRANT_MANAGE,
				group: BackgroundServiceAccount::GROUP,
				register: $this->settingsService->getConfigValue('register'),
				schemas: $schemas
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq reviewer access: NOT reconciled, OpenRegister could not give {group} the special right {right}',
				['group' => BackgroundServiceAccount::GROUP, 'right' => self::GRANT_MANAGE, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end giveTheGrantRight()

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
	 * Read by the walk itself, a page at a time. The shared case type listing
	 * answers one page and answers nothing when its read fails, and a walk
	 * over nothing would report every case reconciled. Here a failed read
	 * throws, so the run says it did not finish.
	 *
	 * @return list<string>
	 */
	private function declaringCaseTypes(): array {
		$ids = [];
		foreach ($this->lookup->everyRowOf(schema: $this->settingsService->getConfigValue('case_type_schema')) as $caseType) {
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
}//end class
