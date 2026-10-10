<?php

/**
 * Drain in-flight `casePlanState` blobs into OpenRegister plan-item rows.
 *
 * Group 2 of retire-cmmn-caseplanstate (design.md section 3). Per case it is
 * convergent, not skip-based: decode the blob, recover the definition from
 * the published caseModel, hand OpenRegister the definition with the recorded
 * states and the event log as history (`CasePlanService::ensureItems()`,
 * which creates only the rows that are missing and audits only those), write
 * the case-file values onto the case, verify every recorded state, and only
 * then clear the blob. Clearing is the per-case commit point, so a crash at
 * any earlier step resumes by creating what is missing.
 *
 * A case it cannot map keeps its blob, untouched, and is reported with its
 * uuid and a reason.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CasePlanDrain
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CasePlanDrain;

use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Migrates one case, or every case of every CMMN case type.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
class CasePlanMigrationService {
	use SearchesObjects;

	public const MIGRATED = 'migrated';

	public const WOULD_MIGRATE = 'would_migrate';

	public const EMPTY = 'empty';

	public const UNMAPPABLE = 'unmappable';

	/**
	 * Page size for the case scan.
	 *
	 * @var integer
	 */
	private const PAGE = 500;

	/**
	 * Decodes and checks the blob.
	 *
	 * @var CasePlanBlob
	 */
	private CasePlanBlob $blob;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Bridge to OpenRegister and config.
	 * @param CasePlanProjectionService $projection      Source of the definition.
	 * @param LoggerInterface           $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CasePlanProjectionService $projection,
		private readonly LoggerInterface $logger,
	) {
		$this->blob = new CasePlanBlob();
	}//end __construct()

	/**
	 * Migrate every case of every CMMN case type.
	 *
	 * @param boolean $dryRun Report, write nothing.
	 *
	 * @return array<int, array<string, mixed>> One report per case that carried a blob, or why none could be read.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function migrateAll(bool $dryRun): array {
		$objects = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		$typeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($objects === null || $register === '' || $caseSchema === '' || $typeSchema === '') {
			return [['caseId' => '', 'outcome' => self::UNMAPPABLE, 'reason' => 'storage_unavailable']];
		}

		// The occ command and the repair step run with no user session; OpenRegister
		// fail-closes an anonymous write, so the whole drain runs as system.
		return $this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: function () use ($objects, $register, $caseSchema, $typeSchema, $dryRun): array {
				$reports = [];
				$types = $this->searchObjectsAsArraysUnscoped(
					objectService: $objects,
					register: $register,
					schema: $typeSchema,
					filters: ['handlingModel' => 'cmmn', '_limit' => self::PAGE]
				);
				foreach ($types as $type) {
					$typeId = (string)($type['id'] ?? $type['uuid'] ?? '');
					foreach ($this->casesOf(objects: $objects, register: $register, schema: $caseSchema, typeId: $typeId) as $case) {
						$report = $this->migrateCase(case: $case, dryRun: $dryRun);
						if ($report['outcome'] !== self::EMPTY) {
							$reports[] = $report;
						}
					}
				}

				return $reports;
			}
		);
	}//end migrateAll()

	/**
	 * Migrate one case by uuid.
	 *
	 * @param string  $caseId The case uuid.
	 * @param boolean $dryRun Report, write nothing.
	 *
	 * @return array<string, mixed> The case's report.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function migrateCaseById(string $caseId, bool $dryRun): array {
		$objects = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objects === null || $register === '' || $schema === '') {
			return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: 'storage_unavailable');
		}

		return $this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: function () use ($objects, $register, $schema, $caseId, $dryRun): array {
				$case = $this->findObjectAsArray(objectService: $objects, register: $register, schema: $schema, id: $caseId);
				if ($case === null) {
					return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: 'case_not_found');
				}

				return $this->migrateCase(case: $case, dryRun: $dryRun);
			}
		);
	}//end migrateCaseById()

	/**
	 * Migrate one case.
	 *
	 * Every refusal before the `ensureItems()` call leaves OpenRegister and the
	 * case as they were. A refusal after it (case file, verification) leaves
	 * the rows that call created, which is safe: the blob stays, and the next
	 * run converges on the same rows.
	 *
	 * @param array<string, mixed> $case   The case object.
	 * @param boolean              $dryRun Report, write nothing.
	 *
	 * @return array<string, mixed> `caseId`, `outcome`, `reason`, `created`, `existing`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function migrateCase(array $case, bool $dryRun): array {
		$caseId = (string)($case['id'] ?? $case['uuid'] ?? '');
		try {
			$blob = $this->blob->decode(raw: ($case['casePlanState'] ?? null));
			if ($blob === null) {
				return $this->report(caseId: $caseId, outcome: self::EMPTY, reason: 'no_blob');
			}

			$definition = $this->projection->definitionForCaseType(caseTypeId: (string)($case['caseType'] ?? ''));
			$definition = $this->blob->withStates(definition: $definition, states: $blob['planItemStates']);
			$history = $this->blob->history(eventLog: $blob['eventLog'], definition: $definition);
			$this->assertCaseFileDeclared(case: $case, caseFile: $blob['caseFile']);
		} catch (UnexpectedValueException $e) {
			return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: $e->getMessage());
		}

		if ($dryRun === true) {
			return $this->report(caseId: $caseId, outcome: self::WOULD_MIGRATE, reason: 'dry_run');
		}

		return $this->commit(caseId: $caseId, blob: $blob, definition: $definition, history: $history);
	}//end migrateCase()

	/**
	 * Ensure the rows, write the case file, verify, clear the blob.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param array<string, mixed> $blob       The decoded blob.
	 * @param array<string, mixed> $definition The definition with states.
	 * @param array<int, mixed>    $history    The history entries.
	 *
	 * @return array<string, mixed> The case's report.
	 */
	private function commit(string $caseId, array $blob, array $definition, array $history): array {
		$plans = $this->settingsService->getOpenRegisterClass(class: CasePlanProjectionService::CASE_PLAN_SERVICE);
		if ($plans === null || method_exists($plans, 'ensureItems') === false) {
			return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: 'case_layer_lacks_ensure_items');
		}

		try {
			$ensured = $plans->ensureItems(
				objectUuid: $caseId,
				registerId: $this->intOrNull(key: 'register'),
				schemaId: $this->intOrNull(key: 'case_schema'),
				definition: $definition,
				history: $history,
				app: CasePlanProjectionService::SYSTEM_APP,
			);
		} catch (Throwable $e) {
			return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: 'refused: ' . $e->getMessage());
		}

		$mismatches = $this->blob->mismatches(states: $blob['planItemStates'], rows: ($ensured['items'] ?? []));
		if ($mismatches !== []) {
			return $this->report(caseId: $caseId, outcome: self::UNMAPPABLE, reason: 'state_mismatch: ' . implode('; ', $mismatches));
		}

		$created = count($ensured['created'] ?? []);
		$existing = count($ensured['existing'] ?? []);
		try {
			$this->writeCase(caseId: $caseId, changes: array_merge($blob['caseFile'], ['casePlanState' => '']));
		} catch (Throwable $e) {
			$this->logger->error('CasePlanMigrationService: rows verified, blob not cleared', ['case' => $caseId, 'exception' => $e->getMessage()]);
			return $this->report(
				caseId: $caseId,
				outcome: self::UNMAPPABLE,
				reason: 'blob_not_cleared: ' . $e->getMessage(),
				created: $created,
				existing: $existing
			);
		}

		return $this->report(caseId: $caseId, outcome: self::MIGRATED, reason: 'verified', created: $created, existing: $existing);
	}//end commit()

	/**
	 * Refuse a case-file slot the case does not declare.
	 *
	 * OpenRegister drops an undeclared property on save, so writing such a
	 * slot and then clearing the blob would lose its value with nothing
	 * reported. A key the case object carries is declared; one it does not
	 * carry might be declared and simply unset, and that case is reported
	 * rather than guessed at, which errs on the side of keeping the blob.
	 *
	 * @param array<string, mixed> $case     The case object.
	 * @param array<string, mixed> $caseFile The blob's case file.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException `case_file_undeclared:<key>`.
	 */
	private function assertCaseFileDeclared(array $case, array $caseFile): void {
		foreach (array_keys($caseFile) as $key) {
			if (array_key_exists((string)$key, $case) === false || in_array((string)$key, ['@self', 'id', 'uuid', 'casePlanState'], true) === true) {
				throw new UnexpectedValueException('case_file_undeclared:' . $key);
			}
		}
	}//end assertCaseFileDeclared()

	/**
	 * Write a partial change onto the case.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $changes The properties to write.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When there is no seam to write through.
	 */
	private function writeCase(string $caseId, array $changes): void {
		$objects = $this->settingsService->getObjectService();
		if ($objects === null) {
			throw new RuntimeException('storage_unavailable');
		}

		$this->patchObjectAsArray(
			objectService: $objects,
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->settingsService->getConfigValue(key: 'case_schema'),
			id: $caseId,
			changes: $changes
		);
	}//end writeCase()

	/**
	 * Every case of one case type, page by page.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema   The case schema id.
	 * @param string $typeId   The case type uuid.
	 *
	 * @return iterable<array<string, mixed>> The cases.
	 */
	private function casesOf(object $objects, string $register, string $schema, string $typeId): iterable {
		if ($typeId === '') {
			return;
		}

		$offset = 0;
		do {
			$page = $this->searchObjectsAsArraysUnscoped(
				objectService: $objects,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $typeId, '_limit' => self::PAGE, '_offset' => $offset]
			);
			yield from $page;
			$offset += self::PAGE;
			$full = (count($page) === self::PAGE);
		} while ($full === true);
	}//end casesOf()

	/**
	 * A numeric config value, or null when it is not set.
	 *
	 * @param string $key The config key.
	 *
	 * @return integer|null The id.
	 */
	private function intOrNull(string $key): ?int {
		$value = $this->settingsService->getConfigValue(key: $key);
		if ($value === '') {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * One case's report.
	 *
	 * @param string  $caseId   The case uuid.
	 * @param string  $outcome  One of the outcome constants.
	 * @param string  $reason   Why.
	 * @param integer $created  Rows created.
	 * @param integer $existing Rows that were already there.
	 *
	 * @return array{caseId: string, outcome: string, reason: string, created: integer, existing: integer} The report.
	 */
	private function report(string $caseId, string $outcome, string $reason, int $created = 0, int $existing = 0): array {
		return ['caseId' => $caseId, 'outcome' => $outcome, 'reason' => $reason, 'created' => $created, 'existing' => $existing];
	}//end report()
}//end class
