<?php

/**
 * Dossiq createSubCase action handler.
 *
 * Action config shape: `{type: 'createSubCase', caseType: '<uuid>', title?, handoffSource?, hoofdzaakField?: 'hoofdzaak'}`.
 * Creates a deelzaak linked to the parent case via `hoofdzaak`, carrying the
 * provenance of whatever opened it when the caller declares one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Kernel\Contracts\CreateSubCaseHandlerInterface;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `createSubCase` automatic actions.
 *
 * @spec openspec/changes/archive/2026-05-11-status-transition-engine/tasks.md#T08
 */
class CreateSubCaseHandler implements ActionHandlerInterface, CreateSubCaseHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the createSubCase action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Parent case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'storage_unavailable');
			}

			$target = $this->caseTarget();
			if ($target === null) {
				return new ActionResult(succeeded: false, error: 'case_schema_not_configured');
			}

			[$register, $caseSchema] = $target;

			$subCase = $this->subCaseRecord(actionConfig: $actionConfig, case: $case, transitionContext: $transitionContext);

			// On the flow path the engine's RegistryStepDispatcher already runs
			// this handler inside `ObjectService::runAs()` as the run's acting
			// identity (openregister#3332); on the interactive path the ambient
			// session user answers the permission checks. No local wrap needed.
			$created = $objectService->saveObject(object: $subCase, register: $register, schema: $caseSchema);
			$subId = $this->createdId(created: $created);

			return new ActionResult(succeeded: true, data: ['subCaseId' => $subId]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateSubCaseHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'create_sub_case_failed');
		}//end try
	}//end handle()

	/**
	 * The configured register and case schema, or null when either is missing.
	 *
	 * @return array{0: string, 1: string}|null The register and the case schema.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function caseTarget(): ?array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $caseSchema === '') {
			return null;
		}

		return [$register, $caseSchema];
	}//end caseTarget()

	/**
	 * The sub-case to create under the parent.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Parent case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return array<string, string> The sub-case record.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function subCaseRecord(array $actionConfig, array $case, array $transitionContext): array {
		$parentId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		$subCase = [
			'title' => (string)($actionConfig['title'] ?? sprintf('Deelzaak na %s', $transitionContext['transitionLabel'] ?? '')),
			'caseType' => (string)($actionConfig['caseType'] ?? ''),
			'hoofdzaak' => $parentId,
		];

		// A case opened by a recurring planned follow-up says which series
		// opened it (ADR-051 provenance). Without it the occurrences of one
		// series are indistinguishable from any other sub-case, and the
		// Related tab would have to scan every case asking where it came
		// from. Absent for every other caller, and absent is not written:
		// an empty `handoffSource` reads as a provenance claim of nothing.
		$handoffSource = trim((string)($actionConfig['handoffSource'] ?? ''));
		if ($handoffSource !== '') {
			$subCase['handoffSource'] = $handoffSource;
		}

		return $subCase;
	}//end subCaseRecord()

	/**
	 * The id of the created sub-case, or '' when the save answered no array.
	 *
	 * @param mixed $created What the save answered.
	 *
	 * @return string The sub-case id.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function createdId(mixed $created): string {
		$subId = '';
		if (is_array($created) === true) {
			$subId = (string)($created['id'] ?? '');
		}

		return $subId;
	}//end createdId()
}//end class
