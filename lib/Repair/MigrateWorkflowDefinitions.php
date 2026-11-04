<?php

/**
 * Dossiq Migrate Workflow Definitions Repair Step
 *
 * Backfill repair step that promotes the implicit lifecycle of every
 * existing caseType into a seeded workflowTemplate published as
 * version 1. Idempotent — skips caseTypes that already have a
 * workflowDefinition reference set.
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Workflow\BackfillCaseType;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateShape;
use OCA\Dossiq\Service\Workflow\BackfillTarget;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Backfill workflowTemplate objects from implicit statusType ordering.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class MigrateWorkflowDefinitions implements IRepairStep {

	use SearchesObjects;

	/**
	 * Per-caseType migration outcomes reported by migrateCaseType().
	 */
	private const OUTCOME_MIGRATED = 'migrated';
	private const OUTCOME_SKIPPED = 'skipped';
	private const OUTCOME_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param WorkflowDefinitionService $workflowService The workflow definition service
	 * @param LoggerInterface $logger The logger interface
	 * @param WorkflowLifecycleGuard $lifecycle Says whether a stored definition is a draft.
	 * @param BackfilledTemplateShape $shape Builds the steps and transitions of a basis template from the ordered status types.
	 * @param BackfilledTemplateLabels $labels Names a basis template, and heals one stored as "Array".
	 */
	public function __construct(
		private SettingsService $settingsService,
		private WorkflowDefinitionService $workflowService,
		private LoggerInterface $logger,
		private readonly WorkflowLifecycleGuard $lifecycle,
		private readonly BackfilledTemplateShape $shape = new BackfilledTemplateShape(),
		private readonly BackfilledTemplateLabels $labels = new BackfilledTemplateLabels(),
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function getName(): string {
		return 'Backfill workflowTemplate definitions for existing caseTypes';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output Repair output channel
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available — skipping workflow backfill.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService not resolvable — skipping workflow backfill.');
			return;
		}

		$target = new BackfillTarget(objectService: $objectService, settings: $this->settingsService);
		if ($target->isConfigured() === false) {
			$output->warning('Workflow backfill: required schema configuration missing — skipping.');
			return;
		}

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls below run inside runAsSystem(). The
		// elevation also covers the nested WorkflowDefinitionService::
		// listVersions() call, since it is scoped to this callable for the
		// whole process rather than to one ObjectService instance.
		$counts = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->migrateAll(target: $target, output: $output)
		);

		$output->info(
			'Workflow backfill complete — migrated ' . $counts[self::OUTCOME_MIGRATED] . ', skipped ' . $counts[self::OUTCOME_SKIPPED] . '.'
		);
	}//end run()

	/**
	 * Migrate every case type, and count what happened.
	 *
	 * @param BackfillTarget $target The register and schemas.
	 * @param IOutput        $output Repair output channel.
	 *
	 * @return array<string, int> Migrated and skipped counts.
	 */
	private function migrateAll(BackfillTarget $target, IOutput $output): array {
		$counts = [self::OUTCOME_MIGRATED => 0, self::OUTCOME_SKIPPED => 0, self::OUTCOME_NONE => 0];
		try {
			$caseTypes = $this->searchObjectsAsArrays(
				objectService: $target->objectService,
				register: $target->register,
				schema: $target->schemas->caseType,
				filters: ['_limit' => 500]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list caseTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			$output->warning('Could not list caseTypes — skipping workflow backfill.');
			return $counts;
		}

		foreach ($caseTypes as $caseType) {
			$counts[$this->migrateCaseType(caseType: $caseType, target: $target)]++;
		}

		return $counts;
	}//end migrateAll()

	/**
	 * Migrate a single caseType row.
	 *
	 * @param mixed          $caseType The caseType as the ObjectService returns it.
	 * @param BackfillTarget $target   The register and schemas.
	 *
	 * @return string One of the OUTCOME_* constants.
	 */
	private function migrateCaseType(mixed $caseType, BackfillTarget $target): string {
		$row = $this->objectToArrayOrNull(value: $caseType);
		$caseTypeId = (string)(($row ?? [])['id'] ?? '');
		if ($row === null || $caseTypeId === '') {
			return self::OUTCOME_NONE;
		}

		$type = new BackfillCaseType(id: $caseTypeId, row: $row, versions: $this->workflowService->listVersions($caseTypeId));
		if ($type->hasAFlow(lifecycle: $this->lifecycle) === true) {
			$this->healBackfilled(target: $target, caseType: $type);
			return self::OUTCOME_SKIPPED;
		}

		$template = $this->buildTemplateFor(target: $target, caseType: $type);
		if ($template === null) {
			return self::OUTCOME_NONE;
		}

		$newId = $this->saveTemplate(target: $target, template: $template);
		if ($newId === null) {
			return self::OUTCOME_NONE;
		}

		// Pin the caseType to the new template.
		if ($newId !== '') {
			$this->pinCaseType(target: $target, caseType: $type->pinnedTo(templateId: $newId));
		}

		return self::OUTCOME_MIGRATED;
	}//end migrateCaseType()

	/**
	 * Save a new basis template.
	 *
	 * @param BackfillTarget       $target   The register and schemas.
	 * @param array<string, mixed> $template The template.
	 *
	 * @return string|null The new id ('' when the store answers none), or null when the save failed.
	 */
	private function saveTemplate(BackfillTarget $target, array $template): ?string {
		try {
			$created = $target->objectService->saveObject(
				object: $template,
				register: $target->register,
				schema: $target->schemas->template,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to save template',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return (string)(($this->objectToArrayOrNull(value: $created) ?? [])['id'] ?? '');
	}//end saveTemplate()

	/**
	 * Heal the basis templates this step stored with array labels.
	 *
	 * The step once cast language-map titles to string, so a template it
	 * wrote then reads "Array" where a title belongs. Only a template carrying
	 * the backfill marker is touched, and the statuses of its case type are
	 * read only when a template needs them. Only the healed fields are
	 * written, through the partial write.
	 *
	 * @param BackfillTarget   $target   The register and schemas.
	 * @param BackfillCaseType $caseType The case type and its templates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	private function healBackfilled(BackfillTarget $target, BackfillCaseType $caseType): void {
		$statuses = null;
		foreach ($caseType->versions as $template) {
			$id = (string)($template['id'] ?? '');
			if ($id === '' || str_contains((string)json_encode($template), BackfilledTemplateLabels::CAST_OF_A_MAP) === false) {
				continue;
			}

			$statuses ??= $this->statusesById(target: $target, caseTypeId: (string)($caseType->row['id'] ?? ''));
			$changes = $this->labels->healed(template: $template, caseType: $caseType->row, statusesById: $statuses);
			if ($changes === []) {
				continue;
			}

			try {
				$this->patchObjectAsArray(
					objectService: $target->objectService,
					register: $target->register,
					schema: $target->schemas->template,
					id: $id,
					changes: $changes,
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Dossiq: workflow backfill could not heal the labels of a basis template',
					['app' => Application::APP_ID, 'template' => $id, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end healBackfilled()

	/**
	 * The statuses of a case type, by id; empty when they cannot be read.
	 *
	 * @param BackfillTarget $target     The register and schemas.
	 * @param string         $caseTypeId The case type.
	 *
	 * @return array<string, array<string, mixed>> The statuses.
	 */
	private function statusesById(BackfillTarget $target, string $caseTypeId): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $target->objectService,
				register: $target->register,
				schema: $target->schemas->status,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: workflow backfill could not read the statuses to heal a basis template',
				['app' => Application::APP_ID, 'caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$byId = [];
		foreach ($rows as $raw) {
			$row = $this->objectToArrayOrNull(value: $raw);
			$id = (string)(($row ?? [])['id'] ?? '');
			if ($row !== null && $id !== '') {
				$byId[$id] = $row;
			}
		}

		return $byId;
	}//end statusesById()

	/**
	 * Pin the caseType to its new template, then its open cases.
	 *
	 * @param BackfillTarget   $target   The register and schemas.
	 * @param BackfillCaseType $caseType The case type, pinned to its template.
	 *
	 * @return void
	 */
	private function pinCaseType(BackfillTarget $target, BackfillCaseType $caseType): void {
		try {
			$target->objectService->saveObject(
				object: $this->withPin(row: $caseType->row, pin: ['workflowDefinition' => $caseType->templateId]),
				register: $target->register,
				schema: $target->schemas->caseType,
				uuid: $caseType->id,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to pin caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}

		// Pin existing open cases to workflowVersion = 1.
		if ($target->schemas->cases === '') {
			return;
		}

		$this->pinOpenCases(target: $target, caseType: $caseType);
	}//end pinCaseType()

	/**
	 * Build a workflowTemplate payload from a caseType's statusTypes.
	 *
	 * @param BackfillTarget   $target   The register and schemas.
	 * @param BackfillCaseType $caseType The case type.
	 *
	 * @return array<string, mixed>|null The template, or null when the case type has no usable statuses.
	 */
	private function buildTemplateFor(BackfillTarget $target, BackfillCaseType $caseType): ?array {
		$statuses = $this->orderedStatuses(target: $target, caseTypeId: $caseType->id);
		if ($statuses === []) {
			return null;
		}

		$steps = $this->shape->steps(statuses: $statuses);
		$transitions = $this->shape->transitions(statuses: $statuses);

		return [
			'title' => $this->labels->titleFor(caseTypeTitle: ($caseType->row['title'] ?? null)),
			'description' => BackfilledTemplateLabels::MARKER,
			'caseType' => $caseType->id,
			'version' => 1,
			'isActive' => true,
			'isDraft' => false,
			'lifecycleStatus' => WorkflowDefinitionService::STATUS_PUBLISHED,
			'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'transitions' => json_encode($transitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'nodePositions' => '',
		];
	}//end buildTemplateFor()

	/**
	 * A case type's statuses with an id, in their order; empty when they cannot be listed.
	 *
	 * @param BackfillTarget $target     The register and schemas.
	 * @param string         $caseTypeId The case type.
	 *
	 * @return array<int, array<string, mixed>> The statuses.
	 */
	private function orderedStatuses(BackfillTarget $target, string $caseTypeId): array {
		try {
			$statusRows = $this->searchObjectsAsArrays(
				objectService: $target->objectService,
				register: $target->register,
				schema: $target->schemas->status,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list statusTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$statuses = [];
		foreach ($statusRows as $raw) {
			$row = $this->objectToArrayOrNull(value: $raw);
			if ($row !== null && (string)($row['id'] ?? '') !== '') {
				$statuses[] = $row;
			}
		}

		usort(
			$statuses,
			static function (array $a, array $b): int {
				return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
			},
		);

		return $statuses;
	}//end orderedStatuses()

	/**
	 * Pin the open cases of a caseType to its new template at version 1.
	 *
	 * @param BackfillTarget   $target   The register and schemas.
	 * @param BackfillCaseType $caseType The case type, pinned to its template.
	 *
	 * @return void
	 */
	private function pinOpenCases(BackfillTarget $target, BackfillCaseType $caseType): void {
		foreach ($this->unpinnedCases(target: $target, caseTypeId: $caseType->id) as $case) {
			try {
				$target->objectService->saveObject(
					object: $this->withPin(
						row: $case,
						pin: [
							'workflowTemplate' => $caseType->templateId,
							'workflowVersion' => 1,
						]
					),
					register: $target->register,
					schema: $target->schemas->cases,
					uuid: (string)$case['id'],
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Dossiq: workflow backfill failed to pin case',
					['app' => Application::APP_ID, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end pinOpenCases()

	/**
	 * The cases of a caseType that have an id and no template yet.
	 *
	 * @param BackfillTarget $target     The register and schemas.
	 * @param string         $caseTypeId The case type.
	 *
	 * @return array<int, array<string, mixed>> The cases; empty when they cannot be listed.
	 */
	private function unpinnedCases(BackfillTarget $target, string $caseTypeId): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $target->objectService,
				register: $target->register,
				schema: $target->schemas->cases,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list cases',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$cases = [];
		foreach ($rows as $row) {
			$case = ($this->objectToArrayOrNull(value: $row) ?? []);
			$caseId = (string)($case['id'] ?? '');
			// Skip rows without an id, and already-pinned cases.
			if ($caseId !== '' && (string)($case['workflowTemplate'] ?? '') === '') {
				$cases[] = $case;
			}
		}

		return $cases;
	}//end unpinnedCases()

	/**
	 * A row with the given fields set, ready to save under its own uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $pin The fields to set.
	 *
	 * @return array<string, mixed> The row to save.
	 */
	private function withPin(array $row, array $pin): array {
		unset($row['@self'], $row['id']);

		return array_merge($row, $pin);
	}//end withPin()
}//end class
