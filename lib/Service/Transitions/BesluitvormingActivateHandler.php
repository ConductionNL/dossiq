<?php

/**
 * Dossiq besluitvormingActivate action handler.
 *
 * Wires the besluitvorming parafering chain into the workflow engine. When a
 * case enters the "Parafering" status step, this auto-action resolves the
 * case's active voorstel and invokes ParaferingRaiseService::activate() to raise
 * the parafeerroute's chain in the decision app.
 *
 * Action config shape: `{type: 'besluitvormingActivate'}`. The voorstel is
 * resolved from the case rather than caller-supplied data.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\Parafeer\ParaferingRaiseService;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Auto-action handler that activates the besluitvorming parafering chain.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class BesluitvormingActivateHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param ParaferingRaiseService $parafeerService Raises the parafering chain in the decision app.
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param FlowRunAsScope $runAsScope Scopes the voorstel lookup and the chain's writes to the run's acting identity.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ParaferingRaiseService $parafeerService,
		private readonly SettingsService $settingsService,
		private readonly FlowRunAsScope $runAsScope,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the besluitvormingActivate action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration.
	 * @param array<string, mixed> $case Case object.
	 * @param array<string, mixed> $transitionContext Transition context.
	 *
	 * @return ActionResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			// The voorstel LOOKUP and the parafering chain's WRITES run under
			// one identity: the run's `runAs` when the flow engine hands one,
			// the ambient session otherwise. Under FlowRunWorker that session
			// carries nobody, so the bare storage work inside activate() is
			// refused as 'Anonymous' however legitimate the run.
			return $this->runAsScope->call(
				context: $transitionContext,
				operation: function () use ($case): ActionResult {
					$proposalId = $this->resolveProposalId(case: $case);
					if ($proposalId === '') {
						return new ActionResult(succeeded: false, error: 'no_active_voorstel');
					}

					$this->parafeerService->activate($proposalId);

					return new ActionResult(succeeded: true, data: ['proposal' => $proposalId]);
				}
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BesluitvormingActivateHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'besluitvorming_activate_failed');
		}//end try
	}//end handle()

	/**
	 * Resolve the active voorstel id for a case.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The voorstel id, or empty string.
	 */
	private function resolveProposalId(array $case): string {
		// A voorstel may be linked directly on the case.
		$direct = (string)($case['proposal'] ?? '');
		if ($direct !== '') {
			return $direct;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$proposalSchema = $this->settingsService->getConfigValue(key: 'voorstel_schema');
		$caseId = (string)($case['id'] ?? $case['uuid'] ?? '');
		if ($register === '' || $proposalSchema === '' || $caseId === '') {
			return '';
		}

		try {
			$results = $objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $proposalSchema, 'case' => $caseId],
					'limit' => 1,
				],
			);

			return $this->firstResultId(results: $results);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'BesluitvormingActivateHandler could not resolve voorstel',
				['exception' => $e->getMessage()],
			);
		}//end try

		return '';
	}//end resolveVoorstelId()

	/**
	 * Read the identifier off the first entry of an ObjectService result set.
	 *
	 * Tolerates both the bare list and the `{results: []}` envelope, and both
	 * array and JsonSerializable entries.
	 *
	 * @param mixed $results Raw ObjectService::findAll() return value.
	 *
	 * @return string The identifier, or empty string when none can be read.
	 */
	private function firstResultId(mixed $results): string {
		if (is_array($results) === true && isset($results['results']) === true) {
			$results = $results['results'];
		}

		if (is_array($results) === false || count($results) === 0) {
			return '';
		}

		$first = $results[0];
		if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
			$first = $first->jsonSerialize();
		}

		if (is_array($first) === false) {
			return '';
		}

		return (string)($first['id'] ?? $first['uuid'] ?? '');
	}//end firstResultId()
}//end class
