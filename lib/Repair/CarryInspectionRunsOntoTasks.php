<?php

/**
 * Repair step: carry every older stored inspection run onto a task.
 *
 * Change inspection-checklists-onto-task, task 4.2. Every `inspectieRapport`,
 * `inspectionChecklistRun` and `inspectionResult` becomes one inspection
 * task through OpenRegister's trusted import path, carrying
 * `metadata.legacyRef`. A source whose reference already sits on one of its
 * case's inspection tasks is skipped, so a second run creates nothing. The
 * sources are not deleted: step 5 retires their schemas one release later.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Inspection\InspectionRunCarryOver;
use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Carries stack A, B and C runs onto inspection tasks.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */
class CarryInspectionRunsOntoTasks implements IRepairStep {
	use SearchesObjects;

	/**
	 * The identity the import is recorded under.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:dossiq';

	/**
	 * Page size for each read.
	 *
	 * @var integer
	 */
	private const LIMIT = 1000;

	/**
	 * Maps a source run onto task fields.
	 *
	 * @var InspectionRunCarryOver
	 */
	private InspectionRunCarryOver $carry;

	/**
	 * Legacy references already on a task, per case.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private array $carried = [];

	/**
	 * Templates by uuid and by legacyRef.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $templates = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Bridge to OpenRegister and config.
	 * @param EngineInboxQuery $inbox           The task inbox read.
	 * @param LoggerInterface  $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineInboxQuery $inbox,
		private readonly LoggerInterface $logger,
	) {
		$this->carry = new InspectionRunCarryOver();
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function getName(): string {
		return 'Carry Dossiq inspection runs onto OpenRegister tasks';
	}//end getName()

	/**
	 * Carry every source not carried yet, and report the counts.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function run(IOutput $output): void {
		$objects = $this->settingsService->getObjectService();
		$tasks = $this->settingsService->getOpenRegisterClass(class: InspectionRunService::TASK_SERVICE);
		$register = $this->settingsService->getConfigValue('register');
		if ($objects === null || $tasks === null || $register === '') {
			$output->warning('OpenRegister, its task layer or the register is not available. Skipping the inspection run carry-over.');
			return;
		}

		$counts = ['carried' => 0, 'already' => 0, 'failed' => 0];
		$this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: function () use ($objects, $tasks, $register, &$counts, $output): void {
				$this->loadTemplates(objects: $objects, register: $register);
				foreach (array_keys(InspectionRunCarryOver::SOURCES) as $schema) {
					foreach ($this->read(objects: $objects, register: $register, schema: $schema) as $object) {
						$this->carryOne(tasks: $tasks, schema: $schema, object: $object, counts: $counts, output: $output);
					}
				}
			}
		);

		$output->info(
			sprintf('Inspection run carry-over: %d carried, %d already carried, %d failed.', $counts['carried'], $counts['already'], $counts['failed'])
		);
	}//end run()

	/**
	 * Carry one source run, unless its case already holds it.
	 *
	 * @param object               $tasks  OpenRegister's TaskService.
	 * @param string               $schema The source schema slug.
	 * @param array<string, mixed> $object The source run.
	 * @param array<string, int>   $counts The running counts.
	 * @param IOutput              $output The output.
	 *
	 * @return void
	 */
	private function carryOne(object $tasks, string $schema, array $object, array &$counts, IOutput $output): void {
		$ref = $this->carry->legacyRef(schema: $schema, object: $object);
		$caseId = (string)($object['case'] ?? '');
		if (isset($this->carriedFor(caseId: $caseId)[$ref]) === true) {
			$counts['already']++;
			return;
		}

		$template = ($this->templates[$this->carry->templateRef(schema: $schema, object: $object)] ?? null);
		try {
			$tasks->import(data: $this->withLocation(data: $this->carry->taskData(schema: $schema, object: $object, template: $template)), actor: self::ACTOR);
		} catch (Throwable $e) {
			$counts['failed']++;
			$this->logger->warning('Inspection run carry-over refused', ['source' => $ref, 'exception' => $e->getMessage()]);
			$output->warning(sprintf('Inspection run %s was not carried over: %s', $ref, $e->getMessage()));
			return;
		}

		$this->carried[$caseId][$ref] = true;
		$counts['carried']++;
	}//end carryOne()

	/**
	 * The legacy references already on a case's inspection tasks.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, bool> Reference => true.
	 */
	private function carriedFor(string $caseId): array {
		if (isset($this->carried[$caseId]) === true) {
			return $this->carried[$caseId];
		}

		$refs = [];
		$rows = $this->inbox->rows(
			criteria: [
				'uid' => self::ACTOR,
				'isAdmin' => true,
				'scope' => $this->inbox->scope(name: 'SCOPE_ALL'),
				'objectUuid' => $caseId,
				'kind' => InspectionRunService::KIND,
			],
			limit: self::LIMIT,
			failure: ['Dossiq: could not read the inspection tasks of a case', ['case' => $caseId]]
		);
		foreach ($rows as $row) {
			$ref = (string)($row['metadata']['legacyRef'] ?? '');
			if ($ref !== '') {
				$refs[$ref] = true;
			}
		}

		$this->carried[$caseId] = $refs;

		return $refs;
	}//end carriedFor()

	/**
	 * Add the case's register and schema, so the task is located.
	 *
	 * @param array<string, mixed> $data The task fields.
	 *
	 * @return array<string, mixed> The task fields, located.
	 */
	private function withLocation(array $data): array {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($register !== '' && $schema !== '') {
			$data['registerId'] = (int)$register;
			$data['schemaId'] = (int)$schema;
		}

		return $data;
	}//end withLocation()

	/**
	 * Index every template by uuid and by the source it was folded from.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 *
	 * @return void
	 */
	private function loadTemplates(object $objects, string $register): void {
		foreach ($this->read(objects: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA) as $template) {
			$id = (string)($template['@self']['id'] ?? $template['id'] ?? '');
			if ($id !== '') {
				$this->templates[$id] = $template;
			}

			$ref = (string)($template['legacyRef'] ?? '');
			if ($ref !== '') {
				$this->templates[$ref] = $template;
			}
		}
	}//end loadTemplates()

	/**
	 * Every object of one schema, or none when the schema is not there.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function read(object $objects, string $register, string $schema): array {
		try {
			return $this->searchObjectsAsArraysUnscoped(objectService: $objects, register: $register, schema: $schema, filters: ['_limit' => self::LIMIT]);
		} catch (Throwable $e) {
			$this->logger->info('Inspection run carry-over: nothing read from ' . $schema, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end read()
}//end class
