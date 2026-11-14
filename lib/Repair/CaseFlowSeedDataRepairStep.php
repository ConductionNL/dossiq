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
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads `lib/Settings/case_flow_seed_data.json` into OpenRegister.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
class CaseFlowSeedDataRepairStep implements IRepairStep {

	use SearchesObjects;

	/**
	 * App-config key holding the seed version whose demo cases were planted.
	 *
	 * @var string
	 */
	public const CASES_SEEDED_KEY = 'case_flow_seed_cases_version';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface  $settingsService Settings and ObjectService bridge.
	 * @param StatusTypeLookup $statuses        Reads a case type's statuses. REUSED rather
	 *                                          than re-queried here: two readers of the
	 *                                          same relationship drift, and this one is
	 *                                          the same object the flow resolves through.
	 * @param CaseFlowSeedIndex $index           Records which seed objects already
	 *                                           landed. Idempotency is kept PER
	 *                                           OBJECT, not per case type: a run
	 *                                           that seeded half the objects and
	 *                                           then failed must be able to finish
	 *                                           on the next repair pass.
	 * @param LoggerInterface   $logger          Logger.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 * @param CaseFlowSeedPhaseBackfill $phaseBackfill Which stored statuses still lack the seed's phase.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly StatusTypeLookup $statuses,
		private readonly CaseFlowSeedIndex $index,
		private readonly LoggerInterface $logger,
		private readonly LanguageMapText $text = new LanguageMapText(),
		private readonly CaseFlowSeedPhaseBackfill $phaseBackfill = new CaseFlowSeedPhaseBackfill(),
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function run(IOutput $output): void {
		$run = $this->prepare(output: $output);
		if ($run === null) {
			return;
		}

		// A repair step runs with no user session, and OpenRegister RBAC is
		// fail-closed for anonymous callers, so every read and write goes
		// through runAsSystem().
		$this->runAsSystemIfAvailable(
			objectService: $run->objectService,
			operation: fn (): array => $this->seed(run: $run)
		);

		$output->info($run->summary());
	}//end run()

	/**
	 * The run, once OpenRegister, the register and schemas and the seed are all there.
	 *
	 * @param IOutput $output Output sink, told why a run is skipped.
	 *
	 * @return CaseFlowSeedRun|null The run, or null when it is skipped.
	 */
	private function prepare(IOutput $output): ?CaseFlowSeedRun {
		$objectService = $this->objectServiceOrSkip(output: $output);
		if ($objectService === null) {
			return null;
		}

		$schemas = $this->schemasOrSkip(output: $output);
		if ($schemas === null) {
			return null;
		}

		$data = (new CaseFlowSeedFile())->load(output: $output);
		if ($data === null) {
			return null;
		}

		return new CaseFlowSeedRun(objectService: $objectService, schemas: $schemas, data: $data, output: $output);
	}//end prepare()

	/**
	 * OpenRegister's object service, or null after saying why there is none.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return object|null
	 */
	private function objectServiceOrSkip(IOutput $output): ?object {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping case-flow seed.');
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('ObjectService unavailable. Skipping case-flow seed.');
		}

		return $objectService;
	}//end objectServiceOrSkip()

	/**
	 * The register and the three schemas this seed writes to, or null after saying they are not configured.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return array<string, string>|null The names, or null when unconfigured.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function schemasOrSkip(IOutput $output): ?array {
		$resolved = [
			'register' => (string)$this->settingsService->getConfigValue(key: 'register'),
			'caseType' => (string)$this->settingsService->getConfigValue(key: 'case_type_schema'),
			'statusType' => (string)$this->settingsService->getConfigValue(key: 'status_type_schema'),
			'case' => (string)$this->settingsService->getConfigValue(key: 'case_schema'),
		];

		if (in_array('', $resolved, true) === true) {
			$output->warning('Register or schemas not configured. Skipping case-flow seed.');
			return null;
		}

		return $resolved;
	}//end schemasOrSkip()

	/**
	 * Create the case type, its statuses and the demo cases.
	 *
	 * @param CaseFlowSeedRun $run The run.
	 *
	 * @return array{caseTypes: int, statuses: int, cases: int, skipped: int} The tally.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function seed(CaseFlowSeedRun $run): array {
		$run->withCases = $this->index->casesDue(data: $run->data);

		foreach (($run->data['caseTypes'] ?? []) as $caseType) {
			if ($this->isSeedable(caseType: $caseType) === true) {
				$this->seedCaseTypeOrReport(run: $run, caseType: $caseType);
			}
		}

		// A run that failed part-way is not recorded, so the next run finishes it.
		if ($run->casesSeeded() === true) {
			$this->settingsService->setConfigValue(key: self::CASES_SEEDED_KEY, value: $this->index->seedVersion(data: $run->data));
		}

		return $run->counts;
	}//end seed()

	/**
	 * Whether a seed entry is a case type with a title.
	 *
	 * @param mixed $caseType The seed entry.
	 *
	 * @return boolean
	 */
	private function isSeedable(mixed $caseType): bool {
		return is_array($caseType) === true && $this->text->textOf(value: ($caseType['title'] ?? null)) !== '';
	}//end isSeedable()

	/**
	 * Seed one case type; a failure is reported and logged, and marks the run incomplete.
	 *
	 * @param CaseFlowSeedRun      $run      The run.
	 * @param array<string, mixed> $caseType The case type from the seed.
	 *
	 * @return void
	 */
	private function seedCaseTypeOrReport(CaseFlowSeedRun $run, array $caseType): void {
		try {
			$this->seedCaseType(run: $run, caseType: $caseType);
		} catch (Throwable $e) {
			$run->complete = false;
			$title = $this->text->textOf(value: ($caseType['title'] ?? null));
			$run->output->warning('Case-flow seed failed for "' . $title . '": ' . $e->getMessage());
			$this->logger->warning(
				'Dossiq case-flow seed failed',
				['caseType' => $title, 'exception' => $e->getMessage()]
			);
		}
	}//end seedCaseTypeOrReport()

	/**
	 * Seed one case type and its statuses, then its demo cases when they are due.
	 *
	 * @param CaseFlowSeedRun      $run      The run.
	 * @param array<string, mixed> $caseType The case type from the seed.
	 *
	 * @return void
	 */
	private function seedCaseType(CaseFlowSeedRun $run, array $caseType): void {
		$caseTypeId = $this->seedOne(
			objectService: $run->objectService,
			schemas: $run->schemas,
			caseType: $caseType,
			counts: $run->counts,
			output: $run->output
		);
		if ($run->withCases === true && $caseTypeId !== '') {
			$run->counts['cases'] += $this->seedCasesOf(run: $run, caseTypeId: $caseTypeId);
		}
	}//end seedCaseType()

	/**
	 * Seed the demo cases of one case type.
	 *
	 * @param CaseFlowSeedRun $run        The run.
	 * @param string          $caseTypeId The case type.
	 *
	 * @return integer The cases created.
	 */
	private function seedCasesOf(CaseFlowSeedRun $run, string $caseTypeId): int {
		return $this->seedCases(
			objectService: $run->objectService,
			schemas: $run->schemas,
			data: $run->data,
			caseTypeId: $caseTypeId,
			statusesByName: array_flip($this->statuses->statusesOf(caseTypeId: $caseTypeId)),
			output: $run->output
		);
	}//end seedCasesOf()

	/**
	 * Seed one case type and its statuses; {@see seed()} plants its cases.
	 *
	 * 🔑 Idempotency is per OBJECT, not per case type. Keying the whole seed on
	 * "does the case type exist" means a run that created the case type and then
	 * failed on its cases — a bad enum value, say — can NEVER complete: every
	 * later run sees the case type and skips everything behind it. Each object
	 * is therefore checked on its own, so a rerun finishes what is missing.
	 *
	 * @param object               $objectService The ObjectService.
	 * @param array<string,string> $schemas       Register and schema names.
	 * @param array<string,mixed>  $caseType      The case type to seed.
	 * @param array<string,int>    $counts        Running totals, updated in place.
	 * @param IOutput              $output        Output sink.
	 *
	 * @return string The case type id, '' when it has none.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function seedOne(
		object $objectService,
		array $schemas,
		array $caseType,
		array &$counts,
		IOutput $output,
	): string {
		$title = $this->text->textOf(value: $caseType['title']);
		$statusTypes = ($caseType['statusTypes'] ?? []);
		unset($caseType['statusTypes']);

		$existing = $this->existingCaseType(schemas: $schemas, caseType: $caseType, title: $title);

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
			return '';
		}

		$counts['statuses'] += $this->seedStatuses(
			objectService: $objectService,
			schemas: $schemas,
			caseTypeId: $caseTypeId,
			statusTypes: $statusTypes
		);

		return $caseTypeId;
	}//end seedOne()

	/**
	 * The case type an earlier run already created, or null.
	 *
	 * By the seed's slug: the title is translatable and stored as a language
	 * map, so a lookup by title never matched and every run created the type
	 * again. A seed row without a slug still falls back to its title.
	 *
	 * @param array<string,string> $schemas  Register and schema names.
	 * @param array<string,mixed>  $caseType The seed's case type.
	 * @param string               $title    Its title.
	 *
	 * @return array<string,mixed>|null The stored case type, or null.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	private function existingCaseType(array $schemas, array $caseType, string $title): ?array {
		$slug = trim((string)($caseType['slug'] ?? ''));
		if ($slug !== '') {
			return $this->index->caseTypeBySlug(schemas: $schemas, slug: $slug);
		}

		return $this->index->caseTypeByTitle(schemas: $schemas, title: $title);
	}//end existingCaseType()

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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function seedStatuses(object $objectService, array $schemas, string $caseTypeId, array $statusTypes): int {
		$present = array_flip($this->statuses->statusesOf(caseTypeId: $caseTypeId));

		$created = 0;
		foreach ($statusTypes as $status) {
			if (is_array($status) === false) {
				continue;
			}

			$name = $this->text->textOf(value: ($status['name'] ?? null));
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

		$this->backfillPhases(
			objectService: $objectService,
			schemas: $schemas,
			caseTypeId: $caseTypeId,
			statusTypes: $statusTypes
		);

		return $created;
	}//end seedStatuses()

	/**
	 * Give the statuses an earlier run stored without a phase the seed's phase.
	 *
	 * A status is only created once, so one seeded before statuses carried a
	 * phase kept none, and the process analysis drew it as a column of its own.
	 * A phase an administrator set is left alone: only an empty one is filled.
	 *
	 * @param object               $objectService The ObjectService.
	 * @param array<string,string> $schemas       Register and schema names.
	 * @param string               $caseTypeId    The owning case type.
	 * @param array<int,mixed>     $statusTypes   The statuses declared in the seed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/seed-status-phases-backfill/spec.md#requirement-a-seeded-status-without-a-phase-gets-the-seeds-phase
	 */
	private function backfillPhases(object $objectService, array $schemas, string $caseTypeId, array $statusTypes): void {
		$pending = $this->phaseBackfill->pending(
			statusTypes: $statusTypes,
			rows: $this->statuses->rowsOf(caseTypeId: $caseTypeId),
			caseTypeId: $caseTypeId
		);
		foreach ($pending as $id => $phase) {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $schemas['register'],
				schema: $schemas['statusType'],
				id: (string)$id,
				changes: ['phase' => $phase]
			);
		}
	}//end backfillPhases()

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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
	 * @spec openspec/specs/case-flow-human-steps/spec.md
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
