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
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateShape;
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
	 * Builds the steps and transitions of a basis template from the ordered status types.
	 *
	 * @var BackfilledTemplateShape
	 */
	private readonly BackfilledTemplateShape $shape;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param WorkflowDefinitionService $workflowService The workflow definition service
	 * @param LoggerInterface $logger The logger interface
	 * @param WorkflowLifecycleGuard $lifecycle Says whether a stored definition is a draft.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 * @param BackfilledTemplateLabels $labels Names a basis template, and heals one stored as "Array".
	 */
	public function __construct(
		private SettingsService $settingsService,
		private WorkflowDefinitionService $workflowService,
		private LoggerInterface $logger,
		private readonly WorkflowLifecycleGuard $lifecycle,
		LanguageMapText $text = new LanguageMapText(),
		private readonly BackfilledTemplateLabels $labels = new BackfilledTemplateLabels(),
	) {
		$this->shape = new BackfilledTemplateShape(text: $text);
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

		$register = $this->settingsService->getConfigValue('register');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		$statusSchema = $this->settingsService->getConfigValue('status_type_schema');
		$templateSchema = $this->settingsService->getConfigValue('workflow_template_schema');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		$missing = in_array('', [$register, $caseTypeSchema, $statusSchema, $templateSchema], true);
		if ($missing === true) {
			$output->warning('Workflow backfill: required schema configuration missing — skipping.');
			return;
		}

		$migrated = 0;
		$skipped = 0;

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls below run inside runAsSystem(). The
		// elevation also covers the nested WorkflowDefinitionService::
		// listVersions() call, since it is scoped to this callable for the
		// whole process rather than to one ObjectService instance.
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use (
				$objectService,
				$register,
				$caseTypeSchema,
				$statusSchema,
				$templateSchema,
				$caseSchema,
				$output,
				&$migrated,
				&$skipped
			): void {
				try {
					$caseTypes = $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: $caseTypeSchema,
						filters: ['_limit' => 500]
					);
				} catch (\Throwable $e) {
					$this->logger->error(
						'Dossiq: workflow backfill failed to list caseTypes',
						['app' => Application::APP_ID, 'exception' => $e->getMessage()]
					);
					$output->warning('Could not list caseTypes — skipping workflow backfill.');
					return;
				}

				foreach ($caseTypes as $caseType) {
					$outcome = $this->migrateCaseType(
						caseType: $caseType,
						objectService: $objectService,
						register: $register,
						caseTypeSchema: $caseTypeSchema,
						statusSchema: $statusSchema,
						templateSchema: $templateSchema,
						caseSchema: $caseSchema,
					);

					if ($outcome === self::OUTCOME_SKIPPED) {
						$skipped++;
					}

					if ($outcome === self::OUTCOME_MIGRATED) {
						$migrated++;
					}
				}//end foreach
			}
		);

		$output->info(
			'Workflow backfill complete — migrated ' . $migrated . ', skipped ' . $skipped . '.'
		);
	}//end run()

	/**
	 * Migrate a single caseType row into a seeded workflowTemplate.
	 *
	 * @param mixed $caseType Raw caseType row from ObjectService
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param string $statusSchema The statusType schema id
	 * @param string $templateSchema The workflowTemplate schema id
	 * @param string $caseSchema The case schema id (may be empty)
	 *
	 * @return string One of the self::OUTCOME_* constants
	 */
	private function migrateCaseType(
		mixed $caseType,
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $statusSchema,
		string $templateSchema,
		string $caseSchema,
	): string {
		$row = $this->objectToArrayOrNull(value: $caseType);
		if ($row === null) {
			return self::OUTCOME_NONE;
		}

		$caseTypeId = (string)($row['id'] ?? '');
		if ($caseTypeId === '') {
			return self::OUTCOME_NONE;
		}

		// Already pinned, or already has a workflowTemplate that is or was
		// published? Skip, the admin sets the pin via the UI. A basis template
		// this step wrote before titles were read as text is healed on the way
		// past. A draft does not count: nobody is offered a draft, so a case
		// type holding only drafts (a package may ship one for an administrator
		// to finish) would have no flow a case can follow.
		$existing = $this->workflowService->listVersions($caseTypeId);
		if ((string)($row['workflowDefinition'] ?? '') !== '' || $this->holdsMoreThanDrafts(templates: $existing) === true) {
			$this->healBackfilled(
				templates: $existing,
				caseType: $row,
				objectService: $objectService,
				register: $register,
				statusSchema: $statusSchema,
				templateSchema: $templateSchema,
			);
			return self::OUTCOME_SKIPPED;
		}

		$template = $this->buildTemplateFor(
			caseTypeId: $caseTypeId,
			caseType: $row,
			objectService: $objectService,
			register: $register,
			statusSchema: $statusSchema,
		);

		if ($template === null) {
			return self::OUTCOME_NONE;
		}

		try {
			$created = $objectService->saveObject(
				object: $template,
				register: $register,
				schema: $templateSchema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to save template',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return self::OUTCOME_NONE;
		}

		$createdNormalized = $this->objectToArrayOrNull(value: $created);
		$newId = (string)($createdNormalized['id'] ?? '');

		// Pin the caseType to the new template.
		if ($newId !== '') {
			$this->pinCaseType(
				objectService: $objectService,
				register: $register,
				caseTypeSchema: $caseTypeSchema,
				caseSchema: $caseSchema,
				caseTypeId: $caseTypeId,
				caseType: $row,
				templateId: $newId,
			);
		}

		return self::OUTCOME_MIGRATED;
	}//end migrateCaseType()

	/**
	 * Whether any of a case type's definitions is, or once was, published.
	 *
	 * @param array<int, array<string, mixed>> $templates The case type's definitions.
	 *
	 * @return bool True when one is published or deprecated.
	 */
	private function holdsMoreThanDrafts(array $templates): bool {
		foreach ($templates as $template) {
			if ($this->lifecycle->statusOf(row: $template) !== WorkflowLifecycleGuard::STATUS_DRAFT) {
				return true;
			}
		}

		return false;
	}//end holdsMoreThanDrafts()

	/**
	 * Heal the basis templates this step stored with "Array" labels.
	 *
	 * Only a template the backfill wrote, and only a label that is literally
	 * "Array" ({@see BackfilledTemplateLabels::healed()}). The statuses are
	 * read only when a template needs them. Only the healed fields are
	 * written, through the partial write.
	 *
	 * @param array<int, array<string, mixed>> $templates      The case type's templates.
	 * @param array<string, mixed>             $caseType       The case type.
	 * @param object                           $objectService  The ObjectService.
	 * @param string                           $register       The register.
	 * @param string                           $statusSchema   The statusType schema.
	 * @param string                           $templateSchema The workflowTemplate schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	private function healBackfilled(
		array $templates,
		array $caseType,
		object $objectService,
		string $register,
		string $statusSchema,
		string $templateSchema,
	): void {
		$statuses = null;
		foreach ($templates as $template) {
			$id = (string)($template['id'] ?? '');
			if ($id === '' || str_contains((string)json_encode($template), BackfilledTemplateLabels::CAST_OF_A_MAP) === false) {
				continue;
			}

			$statuses ??= $this->statusesById(
				objectService: $objectService,
				register: $register,
				statusSchema: $statusSchema,
				caseTypeId: (string)($caseType['id'] ?? ''),
			);
			$changes = $this->labels->healed(template: $template, caseType: $caseType, statusesById: $statuses);
			if ($changes === []) {
				continue;
			}

			try {
				$this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $templateSchema,
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
	 * A case type's statuses, by id.
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register      The register.
	 * @param string $statusSchema  The statusType schema.
	 * @param string $caseTypeId    The case type.
	 *
	 * @return array<string, array<string, mixed>> The statuses.
	 */
	private function statusesById(object $objectService, string $register, string $statusSchema, string $caseTypeId): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $statusSchema,
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
	 * Pin a caseType, and its open cases, to a freshly created template.
	 *
	 * 🔑 THE PIN CARRIES THE WHOLE CASE TYPE. OpenRegister validates and stores
	 * the payload as the complete object, and `caseType` requires a title, so
	 * a one-key update is refused and this step's own catch swallows it. The
	 * row is already in hand from the listing above, so the pin is laid over
	 * it.
	 *
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param string $caseSchema The case schema id (may be empty)
	 * @param string $caseTypeId The caseType UUID
	 * @param array<string, mixed> $caseType The caseType row as read
	 * @param string $templateId The new template UUID
	 *
	 * @return void
	 */
	private function pinCaseType(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $caseSchema,
		string $caseTypeId,
		array $caseType,
		string $templateId,
	): void {
		try {
			$objectService->saveObject(
				object: $this->withPin(row: $caseType, pin: ['workflowDefinition' => $templateId]),
				register: $register,
				schema: $caseTypeSchema,
				uuid: $caseTypeId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to pin caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}

		// Pin existing open cases to workflowVersion = 1.
		if ($caseSchema === '') {
			return;
		}

		$this->pinOpenCases(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			caseTypeId: $caseTypeId,
			templateId: $templateId,
		);
	}//end pinCaseType()

	/**
	 * Build a workflowTemplate payload from a caseType's statusType
	 * records.
	 *
	 * @param string $caseTypeId The caseType UUID
	 * @param array<string, mixed> $caseType Normalized caseType row
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $statusSchema The statusType schema id
	 *
	 * @return array<string, mixed>|null
	 */
	private function buildTemplateFor(
		string $caseTypeId,
		array $caseType,
		object $objectService,
		string $register,
		string $statusSchema,
	): ?array {
		try {
			$statusRows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $statusSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list statusTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($statusRows === []) {
			return null;
		}

		$statuses = [];
		foreach ($statusRows as $raw) {
			$row = $this->objectToArrayOrNull(value: $raw);
			if ($row !== null && (string)($row['id'] ?? '') !== '') {
				$statuses[] = $row;
			}
		}

		if ($statuses === []) {
			return null;
		}

		usort(
			$statuses,
			static function (array $a, array $b): int {
				return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
			},
		);

		$steps = $this->shape->steps(statuses: $statuses);
		$transitions = $this->shape->transitions(statuses: $statuses);

		return [
			'title' => $this->labels->titleFor(caseTypeTitle: ($caseType['title'] ?? null)),
			'description' => BackfilledTemplateLabels::MARKER,
			'caseType' => $caseTypeId,
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
	 * Pin every open case of a caseType to workflowVersion 1 and bind it
	 * to the new workflowTemplate.
	 *
	 * @param object $objectService The OR ObjectService
	 * @param string $register The register id
	 * @param string $caseSchema The case schema id
	 * @param string $caseTypeId The caseType UUID
	 * @param string $templateId The new template UUID
	 *
	 * @return void
	 */
	private function pinOpenCases(
		object $objectService,
		string $register,
		string $caseSchema,
		string $caseTypeId,
		string $templateId,
	): void {
		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list cases',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return;
		}

		foreach ($cases as $row) {
			$case = $this->objectToArrayOrNull(value: $row);
			if ($case === null) {
				continue;
			}

			$caseId = (string)($case['id'] ?? '');
			if ($caseId === '') {
				continue;
			}

			// Skip already-pinned cases.
			if ((string)($case['workflowTemplate'] ?? '') !== '') {
				continue;
			}

			try {
				$objectService->saveObject(
					object: $this->withPin(
						row: $case,
						pin: [
							'workflowTemplate' => $templateId,
							'workflowVersion' => 1,
						]
					),
					register: $register,
					schema: $caseSchema,
					uuid: (string)$caseId,
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
	 * Lay a pin over the row it belongs to, so the update carries a whole object.
	 *
	 * `@self` is OpenRegister's own envelope and `id` is the uuid, which travels
	 * as the `uuid` argument; neither belongs in the payload.
	 *
	 * @param array<string, mixed> $row The row as read.
	 * @param array<string, mixed> $pin The properties to set.
	 *
	 * @return array<string, mixed> The full object to store.
	 */
	private function withPin(array $row, array $pin): array {
		unset($row['@self'], $row['id']);

		return array_merge($row, $pin);
	}//end withPin()
}//end class
