<?php

/**
 * Dossiq repair step: rebind open cases off the backfilled basis flow.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves open cases off the flow the backfill made up, onto the one their case type names.
 *
 * `MigrateWorkflowDefinitions` writes a "(basis)" flow for a case type that
 * ships none: one move per status, no guards, and it binds every open case
 * to it. When the case type later ships a real flow and names it as its
 * `workflowDefinition`, a case keeps the flow it was bound to, so those
 * cases would walk the unguarded basis for the rest of their life. That is
 * how a Woo request reached Publicatie with no documents and no decision
 * (13 October), and the Woo request case type is the first to ship its flow.
 *
 * So, for every case type whose `workflowDefinition` names a published flow
 * that is NOT itself a basis, every open case still bound to a basis flow of
 * that same case type is bound to the named flow. Nothing else moves:
 *
 * - a closed case keeps its history as it was,
 * - a case bound to any flow an administrator built keeps it,
 * - a case with no binding already follows the case type's default.
 *
 * A basis flow is recognised by the marker the backfill writes as its
 * description ({@see BackfilledTemplateLabels::MARKER}), never by its title,
 * which an administrator may have renamed. Running it twice changes nothing.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
class RebindCasesOffTheBasisFlow implements IRepairStep {
	use SearchesObjects;

	/**
	 * The most rows read per listing, the bound the backfill uses too.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Dossiq: move open cases off the backfilled basis flow onto the flow their case type names';
	}//end getName()

	/**
	 * Run the rebind.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseTypeSchema = (string)$this->settingsService->getConfigValue('case_type_schema');
		$templateSchema = (string)$this->settingsService->getConfigValue('workflow_template_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || in_array('', [$register, $caseTypeSchema, $templateSchema, $caseSchema], true) === true) {
			$output->info('Basis flow rebind: OpenRegister or the schema configuration is not available, nothing to do.');
			return;
		}

		$moved = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): int => $this->rebindAll(
				objectService: $objectService,
				register: $register,
				caseTypeSchema: $caseTypeSchema,
				templateSchema: $templateSchema,
				caseSchema: $caseSchema,
			),
		);

		$output->info('Basis flow rebind: ' . (int)$moved . ' open case(s) moved onto the flow their case type names.');
	}//end run()

	/**
	 * Rebind across every case type that names a real flow.
	 *
	 * @param object $objectService  OpenRegister's ObjectService.
	 * @param string $register       The register.
	 * @param string $caseTypeSchema The case type schema.
	 * @param string $templateSchema The workflow template schema.
	 * @param string $caseSchema     The case schema.
	 *
	 * @return int The cases moved.
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public function rebindAll(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $templateSchema,
		string $caseSchema,
	): int {
		try {
			$caseTypes = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseTypeSchema,
				filters: ['_limit' => self::PAGE],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the basis flow rebind could not list case types',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$templates = [];
		$moved = 0;
		foreach ($caseTypes as $caseType) {
			$caseTypeId = (string)($caseType['id'] ?? ($caseType['@self']['id'] ?? ''));
			$named = $this->referenceId(value: ($caseType['workflowDefinition'] ?? ''));
			if ($caseTypeId === '' || $named === '') {
				continue;
			}

			$target = $this->template(
				objectService: $objectService,
				register: $register,
				schema: $templateSchema,
				id: $named,
				cache: $templates,
			);
			if ($target === null || $this->isBasis(template: $target) === true) {
				continue;
			}

			$moved += $this->rebindCaseType(
				objectService: $objectService,
				register: $register,
				templateSchema: $templateSchema,
				caseSchema: $caseSchema,
				caseTypeId: $caseTypeId,
				target: $target,
				templates: $templates,
			);
		}//end foreach

		return $moved;
	}//end rebindAll()

	/**
	 * Rebind the open cases of one case type that are still on one of its basis flows.
	 *
	 * @param object                              $objectService  OpenRegister's ObjectService.
	 * @param string                              $register       The register.
	 * @param string                              $templateSchema The workflow template schema.
	 * @param string                              $caseSchema     The case schema.
	 * @param string                              $caseTypeId     The case type.
	 * @param array<string, mixed>                $target         The flow the case type names.
	 * @param array<string, array<string, mixed>|null> $templates The templates read so far, by id.
	 *
	 * @return int The cases moved.
	 */
	private function rebindCaseType(
		object $objectService,
		string $register,
		string $templateSchema,
		string $caseSchema,
		string $caseTypeId,
		array $target,
		array &$templates,
	): int {
		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => self::PAGE],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the basis flow rebind could not list cases',
				['app' => Application::APP_ID, 'caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$targetId = (string)($target['id'] ?? ($target['@self']['id'] ?? ''));
		$moved = 0;
		foreach ($cases as $case) {
			$from = $this->basisFlowOf(
				case: $case,
				targetId: $targetId,
				caseTypeId: $caseTypeId,
				lookup: fn (string $id): ?array => $this->template(
					objectService: $objectService,
					register: $register,
					schema: $templateSchema,
					id: $id,
					cache: $templates,
				),
			);
			if ($from === null) {
				continue;
			}

			$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
			$row = $case;
			unset($row['@self'], $row['id']);
			$row['workflowTemplate'] = $targetId;
			$row['workflowVersion'] = (int)($target['version'] ?? 1);

			try {
				$objectService->saveObject(object: $row, register: $register, schema: $caseSchema, uuid: $caseId);
				$moved++;
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: the basis flow rebind could not move a case',
					['app' => Application::APP_ID, 'caseId' => $caseId, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $moved;
	}//end rebindCaseType()

	/**
	 * The basis flow an open case of this case type is still bound to, or null.
	 *
	 * Null for a case without an id or a binding, one already on the target,
	 * a closed one, or one bound to any flow that is not this case type's basis.
	 *
	 * @param array<string, mixed>                $case       The case.
	 * @param string                              $targetId   The flow the case type names.
	 * @param string                              $caseTypeId The case type.
	 * @param callable(string): ?array<string, mixed> $lookup  Reads a flow by id.
	 *
	 * @return array<string, mixed>|null The basis flow.
	 */
	private function basisFlowOf(array $case, string $targetId, string $caseTypeId, callable $lookup): ?array {
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		$bound = $this->referenceId(value: ($case['workflowTemplate'] ?? ''));
		if ($caseId === '' || $bound === '' || $bound === $targetId || ($case['isFinalStatus'] ?? false) === true) {
			return null;
		}

		$from = $lookup($bound);
		if ($from === null || $this->isBasis(template: $from) === false) {
			return null;
		}

		if ($this->referenceId(value: ($from['caseType'] ?? '')) !== $caseTypeId) {
			return null;
		}

		return $from;
	}//end basisFlowOf()

	/**
	 * Whether a stored flow is one the backfill made up.
	 *
	 * @param array<string, mixed> $template The flow.
	 *
	 * @return bool
	 */
	private function isBasis(array $template): bool {
		return ($template['description'] ?? null) === BackfilledTemplateLabels::MARKER;
	}//end isBasis()

	/**
	 * Read a flow once.
	 *
	 * @param object                                   $objectService OpenRegister's ObjectService.
	 * @param string                                   $register      The register.
	 * @param string                                   $schema        The workflow template schema.
	 * @param string                                   $id            The flow uuid.
	 * @param array<string, array<string, mixed>|null> $cache         The flows read so far.
	 *
	 * @return array<string, mixed>|null The flow, or null when it cannot be read.
	 */
	private function template(object $objectService, string $register, string $schema, string $id, array &$cache): ?array {
		if (array_key_exists($id, $cache) === false) {
			try {
				$cache[$id] = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
			} catch (Throwable) {
				$cache[$id] = null;
			}
		}

		return $cache[$id];
	}//end template()

	/**
	 * The uuid a reference names, whether stored as a string or an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The uuid, or ''.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['id'] ?? ($value['uuid'] ?? ''));
		}

		return trim((string)$value);
	}//end referenceId()
}//end class
