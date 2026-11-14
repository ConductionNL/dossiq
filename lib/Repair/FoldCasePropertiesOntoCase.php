<?php

/**
 * Dossiq repair step: fold caseProperty rows onto the case that owns them.
 *
 * A case type declares extra properties its cases must answer, via
 * `propertyDefinition` records. Those answers used to be stored one object per
 * answer in the `caseProperty` schema, keyed by `case` + `propertyDefinition`.
 * They now live in a `properties` array on the case itself, so a case reads in
 * one request instead of a query per case, and its answers save in the same
 * write as the case rather than in a second write that can be left behind.
 *
 * This backfills that array for cases created before the change.
 *
 * NON-DESTRUCTIVE ON PURPOSE. The `caseProperty` rows are left exactly where
 * they are. If this projection is wrong, the source is still there to redo it
 * from; deleting the rows in the same step that writes the copy would leave no
 * way back. Removing them is a separate decision for a later release, once the
 * array has been read in anger.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy caseProperty answers into the owning case's `properties` array.
 *
 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
 */
class FoldCasePropertiesOntoCase implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister.
	 * @param IAppConfig      $appConfig       App configuration.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
	 */
	public function getName(): string {
		return 'Fold caseProperty answers onto the case that owns them';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * Non-fatal by construction: an upgrade must not fail because a projection
	 * could not complete, and the source rows are untouched so a later run can
	 * repeat the work.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude one-off data migration; it projects existing rows into the new shape and has no behaviour of its own to specify
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, skipping case-properties backfill.');
			return;
		}

		$schemas = $this->configuredSchemas();
		if ($schemas === null) {
			$output->info('Case-property schemas not configured, skipping backfill.');
			return;
		}

		$this->backfillAsSystem(objectService: $objectService, schemas: $schemas, output: $output);
	}//end run()

	/**
	 * Run the backfill as the system user.
	 *
	 * @param object                                                                              $objectService OpenRegister's object service.
	 * @param array{register: string, caseSchema: string, valueSchema: string, defSchema: string} $schemas       The register and schemas.
	 * @param IOutput                                                                             $output        Progress reporting.
	 *
	 * @return void
	 */
	private function backfillAsSystem(object $objectService, array $schemas, IOutput $output): void {
		// A BLOCK closure, not `fn () => ...`: an arrow function implicitly
		// returns its expression, and returning the result of a void method is
		// a fatal.
		$objectService->runAsSystem(
			function () use ($objectService, $schemas, $output): void {
				$this->backfill(store: $this->caseStore(objectService: $objectService, schemas: $schemas), output: $output);
			}
		);
	}//end backfillAsSystem()

	/**
	 * Read the register and the three schemas the backfill needs.
	 *
	 * @return array{register: string, caseSchema: string, valueSchema: string, defSchema: string}|null Null when one is not configured.
	 */
	private function configuredSchemas(): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$caseSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'case_schema', '');
		$valueSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'case_property_schema', '');
		$defSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'property_definition_schema', '');
		if ($register === '' || $caseSchema === '' || $valueSchema === '' || $defSchema === '') {
			return null;
		}

		return ['register' => $register, 'caseSchema' => $caseSchema, 'valueSchema' => $valueSchema, 'defSchema' => $defSchema];
	}//end configuredSchemas()

	/**
	 * Bundle the object service with the configured register and schemas.
	 *
	 * @param object                                                                              $objectService OpenRegister's object service.
	 * @param array{register: string, caseSchema: string, valueSchema: string, defSchema: string} $schemas       The register and schemas.
	 *
	 * @return CasePropertyStore
	 */
	private function caseStore(object $objectService, array $schemas): CasePropertyStore {
		return new CasePropertyStore(
			objectService: $objectService,
			register: $schemas['register'],
			caseSchema: $schemas['caseSchema'],
			valueSchema: $schemas['valueSchema'],
			defSchema: $schemas['defSchema'],
		);
	}//end caseStore()

	/**
	 * Read the answers and their definitions, then write one array per case.
	 *
	 * @param CasePropertyStore $store  The object service, register and schemas.
	 * @param IOutput           $output Progress reporting.
	 *
	 * @return void
	 */
	private function backfill(CasePropertyStore $store, IOutput $output): void {
		$values = $this->readAll(store: $store, schema: $store->valueSchema);
		if ($values === []) {
			$output->info('No caseProperty rows to fold.');
			return;
		}

		$names = $this->definitionNames(store: $store);
		$byCase = $this->groupByCase(values: $values, names: $names);

		$tally = ['written' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($byCase as $caseId => $entries) {
			$result = $this->foldOne(store: $store, caseId: (string) $caseId, entries: $entries);
			$tally[$result]++;
		}

		$output->info(
			sprintf(
				'Case properties folded: %d written, %d skipped, %d failed.',
				$tally['written'],
				$tally['skipped'],
				$tally['failed']
			)
		);
	}//end backfill()

	/**
	 * Read the definition names once, keyed by id.
	 *
	 * Reading them per answer would be one request for a value that repeats.
	 *
	 * @param CasePropertyStore $store The object service, register and propertyDefinition schema.
	 *
	 * @return array<string, string> Definition id to name.
	 */
	private function definitionNames(CasePropertyStore $store): array {
		$names = [];
		foreach ($this->readAll(store: $store, schema: $store->defSchema) as $def) {
			$id = (string) ($def['id'] ?? $def['uuid'] ?? '');
			if ($id !== '') {
				$names[$id] = (string) ($def['name'] ?? '');
			}
		}

		return $names;
	}//end definitionNames()

	/**
	 * Group the answer rows into one entry list per case.
	 *
	 * @param array<int, array<string, mixed>> $values The caseProperty rows.
	 * @param array<string, string>            $names  Definition id to name.
	 *
	 * @return array<string, array<int, array<string, string>>> Entries per case.
	 */
	private function groupByCase(array $values, array $names): array {
		$byCase = [];
		foreach ($values as $row) {
			$caseId = (string) ($row['case'] ?? '');
			$defId = (string) ($row['propertyDefinition'] ?? '');
			if ($caseId === '' || $defId === '') {
				continue;
			}

			$byCase[$caseId][] = [
				'propertyDefinition' => $defId,
				'name' => ($names[$defId] ?? ''),
				'value' => (string) ($row['value'] ?? ''),
			];
		}

		return $byCase;
	}//end groupByCase()

	/**
	 * Write one case's properties array.
	 *
	 * @param CasePropertyStore                 $store   The object service and case schema.
	 * @param string                            $caseId  The case to write.
	 * @param array<int, array<string, string>> $entries The entries to store.
	 *
	 * @return string One of `written`, `skipped` or `failed`.
	 */
	private function foldOne(CasePropertyStore $store, string $caseId, array $entries): string {
		try {
			return $this->foldInto(store: $store, caseId: $caseId, entries: $entries);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not fold case properties onto a case',
				['case' => $caseId, 'exception' => $e]
			);
			return 'failed';
		}//end try
	}//end foldOne()

	/**
	 * Read one case and write its properties array when it has none yet.
	 *
	 * @param CasePropertyStore                 $store   The object service and case schema.
	 * @param string                            $caseId  The case to write.
	 * @param array<int, array<string, string>> $entries The entries to store.
	 *
	 * @return string Either `written` or `skipped`.
	 *
	 * @throws Throwable When reading or saving the case fails.
	 */
	private function foldInto(CasePropertyStore $store, string $caseId, array $entries): string {
		$objectService = $store->objectService;
		$case = $this->readCase(objectService: $objectService, caseId: $caseId);
		if (is_array($case) === false) {
			return 'skipped';
		}

		// An existing array is the newer truth: it was written either by the
		// form or by an earlier run. Overwriting it with a projection of the
		// old rows would undo a real edit.
		if (empty($case['properties']) === false) {
			return 'skipped';
		}

		$case['properties'] = $entries;
		$objectService->saveObject($store->caseSchema, $case, ['_rbac' => false, '_multitenancy' => false]);
		return 'written';
	}//end foldInto()

	/**
	 * Read one case unscoped, as a plain array when the store returns an entity.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $caseId        The case to read.
	 *
	 * @return mixed The case, or whatever the store answered.
	 *
	 * @throws Throwable When the read fails.
	 */
	private function readCase(object $objectService, string $caseId): mixed {
		$case = $objectService->find($caseId, ['_rbac' => false, '_multitenancy' => false]);
		if (is_object($case) === true && method_exists($case, 'jsonSerialize') === true) {
			$case = $case->jsonSerialize();
		}

		return $case;
	}//end readCase()

	/**
	 * Read every object of one schema as plain arrays.
	 *
	 * `occ` has no session, so every ObjectService call runs as Anonymous and
	 * needs the access flags explicitly.
	 *
	 * @param CasePropertyStore $store  The object service and register.
	 * @param string            $schema The schema id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function readAll(CasePropertyStore $store, string $schema): array {
		$rows = $store->objectService->findAll(
			[
				'filters' => ['register' => $store->register, 'schema' => $schema],
				'_rbac' => false,
				'_multitenancy' => false,
			]
		);
		if (is_array($rows) === false) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end readAll()
}//end class
