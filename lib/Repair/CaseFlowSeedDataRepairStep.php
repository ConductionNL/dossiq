<?php

/**
 * Seeds the case type, statuses and demo cases the shipped case flow needs.
 *
 * 🔴 WHY THIS EXISTS, STATED PLAINLY. `case_flow_seed_data.json` was added
 * without an importer. Nothing referenced it, so the case type and its six
 * statuses never existed — and because the flow moves a case by NAMING a
 * status, every `dossiq.setStatus` step would have refused with
 * `status_not_found_on_case_type`. The flow imported, looked complete in the
 * editor, and could not advance a single case. The e2e caught it; no unit test
 * could, because the data was well-formed — it simply was not loaded.
 *
 * 🔑 THE LINK LIVES ON THE CHILD. `caseType` has no `statusTypes` property;
 * every `statusType` carries a `caseType` back-reference, which is how
 * TemplateLibraryService creates them. Seeding the statuses as children of the
 * case type is therefore not a style choice — it is the only shape
 * {@see \OCA\Dossiq\Service\Transitions\StatusTypeLookup} can read.
 *
 * Idempotent: an existing case type of this slug is left alone and its statuses
 * are not duplicated. Re-running is a no-op, because a repair step runs on
 * every upgrade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads `lib/Settings/case_flow_seed_data.json` into OpenRegister.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
class CaseFlowSeedDataRepairStep implements IRepairStep {

	use SearchesObjects;

	/**
	 * Location of the case-flow seed, relative to this file.
	 *
	 * @var string
	 */
	private const SEED_PATH = __DIR__ . '/../Settings/case_flow_seed_data.json';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Settings and ObjectService bridge.
	 * @param StatusTypeLookup $statuses        Reads a case type's statuses. REUSED rather
	 *                                          than re-queried here: two readers of the
	 *                                          same relationship drift, and this one is
	 *                                          the same object the flow resolves through.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTypeLookup $statuses,
		private readonly CaseFlowSeedIndex $index,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function getName(): string {
		return 'Seed the case type, statuses and demo cases for the Dossiq case flow';
	}//end getName()

	/**
	 * Run the seed.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping case-flow seed.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('ObjectService unavailable. Skipping case-flow seed.');
			return;
		}

		$schemas = $this->schemas();
		if ($schemas === null) {
			$output->warning('Register or schemas not configured. Skipping case-flow seed.');
			return;
		}

		$data = $this->loadSeed(output: $output);
		if ($data === null) {
			return;
		}

		// A repair step runs with no user session, and OpenRegister RBAC is
		// fail-closed for anonymous callers, so every read and write goes
		// through runAsSystem().
		$summary = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $schemas, $data, $output): array {
				return $this->seed(
					objectService: $objectService,
					schemas: $schemas,
					data: $data,
					output: $output
				);
			}
		);

		$output->info(
			sprintf(
				'Case-flow seed: %d case type(s), %d status(es), %d case(s) created; %d already present.',
				$summary['caseTypes'],
				$summary['statuses'],
				$summary['cases'],
				$summary['skipped']
			)
		);
	}//end run()

	/**
	 * The register and the three schemas this seed writes to.
	 *
	 * @return array<string, string>|null The names, or null when unconfigured.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function schemas(): ?array {
		$resolved = [
			'register' => (string)$this->settingsService->getConfigValue(key: 'register'),
			'caseType' => (string)$this->settingsService->getConfigValue(key: 'case_type_schema'),
			'statusType' => (string)$this->settingsService->getConfigValue(key: 'status_type_schema'),
			'case' => (string)$this->settingsService->getConfigValue(key: 'case_schema'),
		];

		foreach ($resolved as $value) {
			if ($value === '') {
				return null;
			}
		}

		return $resolved;
	}//end schemas()

	/**
	 * Read and decode the seed file.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return array<string, mixed>|null The seed, or null when unreadable.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function loadSeed(IOutput $output): ?array {
		if (is_readable(self::SEED_PATH) === false) {
			$output->warning('Case-flow seed file not found at ' . self::SEED_PATH);
			return null;
		}

		$decoded = json_decode((string)file_get_contents(self::SEED_PATH), true);
		if (is_array($decoded) === false) {
			$output->warning('Case-flow seed file is not valid JSON.');
			return null;
		}

		return $decoded;
	}//end loadSeed()

	/**
	 * Create the case type, its statuses and the demo cases.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string,string> $schemas       Register and schema names.
	 * @param array<string,mixed>  $data          The decoded seed.
	 * @param IOutput              $output        Output sink.
	 *
	 * @return array<string,int> Counts of what was created.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function seed(object $objectService, array $schemas, array $data, IOutput $output): array {
		$counts = ['caseTypes' => 0, 'statuses' => 0, 'cases' => 0, 'skipped' => 0];

		foreach (($data['caseTypes'] ?? []) as $caseType) {
			if (is_array($caseType) === false || trim((string)($caseType['title'] ?? '')) === '') {
				continue;
			}

			try {
				$this->seedOne(
					objectService: $objectService,
					schemas: $schemas,
					data: $data,
					caseType: $caseType,
					counts: $counts,
					output: $output
				);
			} catch (Throwable $e) {
				$title = (string)($caseType['title'] ?? '');
				$output->warning('Case-flow seed failed for "' . $title . '": ' . $e->getMessage());
				$this->logger->warning(
					'Dossiq case-flow seed failed',
					['caseType' => $title, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $counts;
	}//end seed()

	/**
	 * Seed one case type, its statuses and its cases.
	 *
	 * 🔑 Idempotency is per OBJECT, not per case type. Keying the whole seed on
	 * "does the case type exist" means a run that created the case type and then
	 * failed on its cases — a bad enum value, say — can NEVER complete: every
	 * later run sees the case type and skips everything behind it. Each object
	 * is therefore checked on its own, so a rerun finishes what is missing.
	 *
	 * @param object               $objectService The ObjectService.
	 * @param array<string,string> $schemas       Register and schema names.
	 * @param array<string,mixed>  $data          The decoded seed.
	 * @param array<string,mixed>  $caseType      The case type to seed.
	 * @param array<string,int>    $counts        Running totals, updated in place.
	 * @param IOutput              $output        Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function seedOne(
		object $objectService,
		array $schemas,
		array $data,
		array $caseType,
		array &$counts,
		IOutput $output,
	): void {
		$title = (string)$caseType['title'];
		$statusTypes = ($caseType['statusTypes'] ?? []);
		unset($caseType['statusTypes']);

		$existing = $this->index->caseTypeByTitle(schemas: $schemas, title: $title);

		$caseTypeId = '';
		if ($existing !== null) {
			$caseTypeId = $this->idOf(value: $existing);
			$counts['skipped']++;
		}

		if ($existing === null) {
			$created = $objectService->saveObject(
				object: $caseType,
				register: $schemas['register'],
				schema: $schemas['caseType']
			);
			$caseTypeId = $this->idOf(value: $created);
			$counts['caseTypes']++;
		}

		if ($caseTypeId === '') {
			$output->warning('Case-flow seed: the case type has no id; skipping its statuses.');
			return;
		}

		$counts['statuses'] += $this->seedStatuses(
			objectService: $objectService,
			schemas: $schemas,
			caseTypeId: $caseTypeId,
			statusTypes: $statusTypes
		);

		$counts['cases'] += $this->seedCases(
			objectService: $objectService,
			schemas: $schemas,
			data: $data,
			caseTypeId: $caseTypeId,
			statusesByName: array_flip($this->statuses->statusesOf(caseTypeId: $caseTypeId)),
			output: $output
		);
	}//end seedOne()

	/**
	 * Create the statuses this case type is missing.
	 *
	 * 🔑 Each carries the `caseType` back-reference, which is the ONLY link the
	 * status lookup can follow — `caseType` has no `statusTypes` property.
	 *
	 * @param object               $objectService The ObjectService.
	 * @param array<string,string> $schemas       Register and schema names.
	 * @param string               $caseTypeId    The owning case type.
	 * @param array<int,mixed>     $statusTypes   The statuses declared in the seed.
	 *
	 * @return integer How many were created.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function seedStatuses(object $objectService, array $schemas, string $caseTypeId, array $statusTypes): int {
		$present = array_flip($this->statuses->statusesOf(caseTypeId: $caseTypeId));

		$created = 0;
		foreach ($statusTypes as $status) {
			if (is_array($status) === false) {
				continue;
			}

			$name = (string)($status['name'] ?? '');
			if ($name === '' || isset($present[$name]) === true) {
				continue;
			}

			$status['caseType'] = $caseTypeId;
			$saved = $objectService->saveObject(
				object: $status,
				register: $schemas['register'],
				schema: $schemas['statusType']
			);

			if ($this->idOf(value: $saved) !== '') {
				$created++;
			}
		}

		return $created;
	}//end seedStatuses()

	/**
	 * Create the demo cases belonging to this case type.
	 *
	 * @param object               $objectService  The ObjectService.
	 * @param array<string,string> $schemas        Register and schema names.
	 * @param array<string,mixed>  $data           The decoded seed.
	 * @param string               $caseTypeId     The created case type.
	 * @param array<string,string> $statusesByName Status ids, keyed by name.
	 * @param IOutput              $output         Output sink.
	 *
	 * @return integer How many cases were created.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function seedCases(
		object $objectService,
		array $schemas,
		array $data,
		string $caseTypeId,
		array $statusesByName,
		IOutput $output,
	): int {
		$created = 0;

		$present = $this->index->caseTitlesFor(schemas: $schemas, caseTypeId: $caseTypeId);

		foreach (($data['cases'] ?? []) as $case) {
			if (is_array($case) === false) {
				continue;
			}

			if (in_array((string)($case['title'] ?? ''), $present, true) === true) {
				continue;
			}

			$statusName = (string)($case['status'] ?? '');
			unset($case['caseTypeSlug']);

			$case['caseType'] = $caseTypeId;

			// A case whose status name does not resolve is written WITHOUT a
			// status rather than with a dangling one: an unresolvable
			// reference reads as a real status everywhere it is displayed.
			unset($case['status']);
			if (isset($statusesByName[$statusName]) === true) {
				$case['status'] = $statusesByName[$statusName];
			}

			try {
				$objectService->saveObject(
					object: $case,
					register: $schemas['register'],
					schema: $schemas['case']
				);
				$created++;
			} catch (Throwable $e) {
				$output->warning(
					'Case-flow seed: could not create case "' . (string)($case['title'] ?? '') . '": ' . $e->getMessage()
				);
			}
		}//end foreach

		return $created;
	}//end seedCases()





	/**
	 * The id of a saved object, whatever shape the store returned.
	 *
	 * @param mixed $value The saved object.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
	 */
	private function idOf(mixed $value): string {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return '';
		}

		return (string)($value['id'] ?? ($value['uuid'] ?? ''));
	}//end idOf()
}//end class
