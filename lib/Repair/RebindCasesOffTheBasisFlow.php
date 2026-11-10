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
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
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
				run: new FlowRebindRun(
					objectService: $objectService,
					register: $register,
					schemas: ['caseType' => $caseTypeSchema, 'template' => $templateSchema, 'case' => $caseSchema],
				),
			),
		);

		$output->info('Basis flow rebind: ' . (int)$moved . ' open case(s) moved onto the flow their case type names.');
	}//end run()

	/**
	 * Rebind across every case type that names a real flow.
	 *
	 * @param FlowRebindRun $run The store, the schemas and the flows read so far.
	 *
	 * @return int The cases moved.
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public function rebindAll(FlowRebindRun $run): int {
		$moved = 0;
		foreach ($this->caseTypesOf(run: $run) as $caseType) {
			$binding = $this->bindingOf(run: $run, caseType: $caseType);
			if ($binding !== null) {
				$moved += $this->rebindCaseType(run: $run, binding: $binding);
			}
		}

		return $moved;
	}//end rebindAll()

	/**
	 * Every case type, or none when they cannot be listed.
	 *
	 * @param FlowRebindRun $run The run.
	 *
	 * @return array<int, array<string, mixed>> The case types.
	 */
	private function caseTypesOf(FlowRebindRun $run): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->schemas['caseType'],
				filters: ['_limit' => self::PAGE],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the basis flow rebind could not list case types',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end caseTypesOf()

	/**
	 * The cases of one case type, or none when they cannot be listed.
	 *
	 * @param FlowRebindRun                                              $run     The run.
	 * @param array{caseTypeId: string, target: array<string, mixed>} $binding The case type and the flow it names.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function casesOf(FlowRebindRun $run, array $binding): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->schemas['case'],
				filters: ['caseType' => $binding['caseTypeId'], '_limit' => self::PAGE],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the basis flow rebind could not list cases',
				['app' => Application::APP_ID, 'caseType' => $binding['caseTypeId'], 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end casesOf()

	/**
	 * The case type and the real flow it names, or null when it names none.
	 *
	 * @param FlowRebindRun        $run      The run.
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array{caseTypeId: string, target: array<string, mixed>}|null
	 */
	private function bindingOf(FlowRebindRun $run, array $caseType): ?array {
		$caseTypeId = (string)($caseType['id'] ?? ($caseType['@self']['id'] ?? ''));
		$named = $this->referenceId(value: ($caseType['workflowDefinition'] ?? ''));
		if ($caseTypeId === '' || $named === '') {
			return null;
		}

		$target = $this->template(run: $run, id: $named);
		if ($target === null || $this->isBasis(template: $target) === true) {
			return null;
		}

		return ['caseTypeId' => $caseTypeId, 'target' => $target];
	}//end bindingOf()

	/**
	 * Rebind the open cases of one case type that are still on one of its basis flows.
	 *
	 * @param FlowRebindRun                                              $run     The run.
	 * @param array{caseTypeId: string, target: array<string, mixed>} $binding The case type and the flow it names.
	 *
	 * @return int The cases moved.
	 */
	private function rebindCaseType(FlowRebindRun $run, array $binding): int {
		$moved = 0;
		foreach ($this->casesOf(run: $run, binding: $binding) as $case) {
			if ($this->basisFlowOf(run: $run, case: $case, binding: $binding) === null) {
				continue;
			}

			if ($this->moveCase(run: $run, case: $case, target: $binding['target']) === true) {
				$moved++;
			}
		}

		return $moved;
	}//end rebindCaseType()

	/**
	 * Bind one case to the target flow, keeping everything else on it.
	 *
	 * @param FlowRebindRun        $run    The run.
	 * @param array<string, mixed> $case   The case.
	 * @param array<string, mixed> $target The flow the case type names.
	 *
	 * @return bool Whether the case was saved.
	 */
	private function moveCase(FlowRebindRun $run, array $case, array $target): bool {
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		$row = $case;
		unset($row['@self'], $row['id']);
		$row['workflowTemplate'] = (string)($target['id'] ?? ($target['@self']['id'] ?? ''));
		$row['workflowVersion'] = (int)($target['version'] ?? 1);

		try {
			$run->objectService->saveObject(
				object: $row,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $run->register, schema: $run->schemas['case']),
					uuid: $caseId
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the basis flow rebind could not move a case',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end moveCase()

	/**
	 * The basis flow an open case of this case type is still bound to, or null.
	 *
	 * Null for a case without an id or a binding, one already on the target,
	 * a closed one, or one bound to any flow that is not this case type's basis.
	 *
	 * @param FlowRebindRun                                              $run     The run.
	 * @param array<string, mixed>                                       $case    The case.
	 * @param array{caseTypeId: string, target: array<string, mixed>} $binding The case type and the flow it names.
	 *
	 * @return array<string, mixed>|null The basis flow.
	 */
	private function basisFlowOf(FlowRebindRun $run, array $case, array $binding): ?array {
		$bound = $this->movableFrom(case: $case, binding: $binding);
		if ($bound === '') {
			return null;
		}

		$from = $this->readTemplate(run: $run, id: $bound);
		if ($from === null || $this->isBasis(template: $from) === false) {
			return null;
		}

		if ($this->referenceId(value: ($from['caseType'] ?? '')) !== $binding['caseTypeId']) {
			return null;
		}

		return $from;
	}//end basisFlowOf()

	/**
	 * The flow an open case is bound to, or '' for a case without an id or a
	 * binding, one already on the target, or a closed one.
	 *
	 * @param array<string, mixed>                                       $case    The case.
	 * @param array{caseTypeId: string, target: array<string, mixed>} $binding The case type and the flow it names.
	 *
	 * @return string The flow uuid, or ''.
	 */
	private function movableFrom(array $case, array $binding): string {
		$targetId = (string)($binding['target']['id'] ?? ($binding['target']['@self']['id'] ?? ''));
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		$bound = $this->referenceId(value: ($case['workflowTemplate'] ?? ''));
		if ($caseId === '' || $bound === '' || $bound === $targetId || ($case['isFinalStatus'] ?? false) === true) {
			return '';
		}

		return $bound;
	}//end movableFrom()

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
	 * @param FlowRebindRun $run The run, holding the flows read so far.
	 * @param string        $id  The flow uuid.
	 *
	 * @return array<string, mixed>|null The flow, or null when it cannot be read.
	 */
	private function template(FlowRebindRun $run, string $id): ?array {
		if (array_key_exists($id, $run->templates) === false) {
			$run->templates[$id] = $this->readTemplate(run: $run, id: $id);
		}

		return $run->templates[$id];
	}//end template()

	/**
	 * A flow from the flows read so far, or read from the store without keeping it.
	 *
	 * @param FlowRebindRun $run The run, holding the flows read so far.
	 * @param string        $id  The flow uuid.
	 *
	 * @return array<string, mixed>|null The flow, or null when it cannot be read.
	 */
	private function readTemplate(FlowRebindRun $run, string $id): ?array {
		if (array_key_exists($id, $run->templates) === true) {
			return $run->templates[$id];
		}

		try {
			return $this->findObjectAsArray(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->schemas['template'],
				id: $id
			);
		} catch (Throwable) {
			return null;
		}
	}//end readTemplate()

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
