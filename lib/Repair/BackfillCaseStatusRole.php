<?php

/**
 * Dossiq repair step: give existing cases the role of the status they sit in.
 *
 * `case.statusRole` is computed by OpenRegister from the linked status type's
 * `role`, and OpenRegister computes it when a case is SAVED. A case that was
 * last saved before the field existed has none, so the simple case page shows
 * it no what-now card and no stage button until somebody happens to edit it.
 *
 * OpenRegister's own `occ openregister:rematerialise-calculations` is the tool
 * for this, and on 5 October 2026 it failed for every case: it writes as
 * "Anonymous", and the case schema refuses an anonymous update. This step does
 * the same job in system context, the way dossiq's other backfills write.
 *
 * What it writes, and what it leaves alone:
 * - only a case whose status type DECLARES a role, and whose `statusRole`
 *   differs from it. A case type nobody annotated is skipped, and so is a case
 *   that is already right, which is what makes a second run a no-op;
 * - one field, `statusRole`, with the value OpenRegister computes itself. It
 *   changes no status, so it is no transition: `StatusTransitionService` is not
 *   involved, and the notifications the case schema declares (assigned on
 *   create, declared major on an `isMajor` change, moved on a transition) have
 *   nothing to fire on. The applicant is told nothing.
 *
 * It never throws. One row that refuses is logged and counted, and the
 * upgrade goes on.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md#REQ-CM-77
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfill `statusRole` on the cases that exist.
 *
 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md#REQ-CM-77
 */
class BackfillCaseStatusRole implements IRepairStep {

	use SearchesObjects;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on the paging, so a store that never answers a short page
	 * cannot keep an upgrade running for ever.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the register and the schemas, and hands out the object service.
	 * @param LoggerInterface $logger          Records a row that refused.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md#REQ-CM-77
	 */
	public function getName(): string {
		return 'Give existing cases the role of the status they sit in (statusRole)';
	}//end getName()

	/**
	 * Run the backfill and say what it did.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md#REQ-CM-77
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping the statusRole backfill.');
			return;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$statusTypeSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
		if ($register === '' || $caseSchema === '' || $statusTypeSchema === '') {
			$output->info('statusRole backfill: the case or status type schema is not configured, skipped.');
			return;
		}

		try {
			$tally = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->backfill(
					objectService: $objectService,
					register: $register,
					caseSchema: $caseSchema,
					statusTypeSchema: $statusTypeSchema
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the statusRole backfill could not read the cases',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			$output->info('statusRole backfill: the cases could not be read, skipped.');
			return;
		}

		$output->info(
			'statusRole backfill: ' . (int)($tally['filled'] ?? 0) . ' filled, '
			. (int)($tally['unconfirmed'] ?? 0) . ' written but not confirmed, '
			. (int)($tally['failed'] ?? 0) . ' failed, '
			. (int)($tally['skipped'] ?? 0) . ' already right or without a role.'
		);
	}//end run()

	/**
	 * The role a case should carry, or an empty string when it needs no write.
	 *
	 * Empty when the case has no status, when its status type declares no
	 * role, and when the case already carries that role.
	 *
	 * @param array<string, mixed>  $case  The stored case.
	 * @param array<string, string> $roles Role by status type uuid.
	 *
	 * @return string The role to write, or ''.
	 *
	 * @spec openspec/changes/simple-case-page/specs/case-management/spec.md#REQ-CM-77
	 */
	public function roleToWrite(array $case, array $roles): string {
		$status = ($case['status'] ?? null);
		if (is_array($status) === true) {
			// An extended read hands the status type over whole.
			$status = ($status['id'] ?? ($status['uuid'] ?? null));
		}

		if (is_string($status) === false || $status === '') {
			return '';
		}

		$role = ($roles[strtolower($status)] ?? '');
		if ($role === '' || ($case['statusRole'] ?? null) === $role) {
			return '';
		}

		return $role;
	}//end roleToWrite()

	/**
	 * Walk every case and write the role where it is missing or stale.
	 *
	 * @param object $objectService    The OpenRegister object service.
	 * @param string $register         The dossiq register.
	 * @param string $caseSchema       The case schema.
	 * @param string $statusTypeSchema The status type schema.
	 *
	 * @return array{filled: int, unconfirmed: int, failed: int, skipped: int} What happened.
	 */
	private function backfill(object $objectService, string $register, string $caseSchema, string $statusTypeSchema): array {
		$tally = ['filled' => 0, 'unconfirmed' => 0, 'failed' => 0, 'skipped' => 0];
		$roles = $this->rolesByStatusType(objectService: $objectService, register: $register, schema: $statusTypeSchema);
		if ($roles === []) {
			return $tally;
		}

		// The cases are read first and written after. Writing while paging by
		// offset can move a row between pages, and then one case is seen twice
		// and another not at all.
		$todo = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$role = $this->roleToWrite(case: $row, roles: $roles);
				$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
				if ($role === '' || $uuid === '') {
					$tally['skipped']++;
					continue;
				}

				$todo[$uuid] = $role;
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		foreach ($todo as $uuid => $role) {
			$outcome = $this->writeRole(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				uuid: (string)$uuid,
				role: $role
			);
			$tally[$outcome]++;
		}

		return $tally;
	}//end backfill()

	/**
	 * Write one case's role and report how it went.
	 *
	 * `filled` when the stored case answers the role back. `unconfirmed` when
	 * the write went through and the answer does not carry the role, which is
	 * a store that dropped the field and worth a line in the output.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The case schema.
	 * @param string $uuid          The case.
	 * @param string $role          The role to write.
	 *
	 * @return string `filled`, `unconfirmed` or `failed`.
	 */
	private function writeRole(object $objectService, string $register, string $schema, string $uuid, string $role): string {
		try {
			$stored = $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $uuid,
				changes: ['statusRole' => $role]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the statusRole backfill failed for one case',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		if (($stored['statusRole'] ?? null) === $role) {
			return 'filled';
		}

		return 'unconfirmed';
	}//end writeRole()

	/**
	 * The role every status type declares, by status type uuid.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The dossiq register.
	 * @param string $schema        The status type schema.
	 *
	 * @return array<string, string> Role by lower-cased uuid; a status type without a role is left out.
	 */
	private function rolesByStatusType(object $objectService, string $register, string $schema): array {
		$roles = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
				$role = ($row['role'] ?? null);
				if ($uuid !== '' && is_string($role) === true && $role !== '') {
					$roles[strtolower($uuid)] = $role;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $roles;
	}//end rolesByStatusType()
}//end class
