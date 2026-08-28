<?php

/**
 * Move a case to a named status.
 *
 * WHY THIS EXISTS ALONGSIDE {@see SetFieldHandler}. `setField` writes a literal
 * value, which is right for a date or a flag and wrong for `status`: status is a
 * reference to a `statusType` object whose uuid is minted per installation. A
 * flow SHIPPED with the app therefore cannot carry one — it would be correct on
 * the machine it was authored on and wrong everywhere else, and wrong in the
 * quiet way, by writing a uuid nothing resolves.
 *
 * So this names the status the way a person does — "in behandeling" — and
 * resolves it within the case's OWN case type at run time.
 *
 * 🔴 IT REFUSES RATHER THAN SKIPS. An unresolvable status fails the step. The
 * tempting alternative — leave the status alone and carry on — produces a run
 * that completes while the case never moved, which the applicant experiences as
 * a case frozen at "received" and the handler experiences as a flow that says it
 * worked. A status move is the applicant-facing signal; failing to make one is
 * a failure, not a detail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Handles the `setStatus` action: move a case to a status named by the flow.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */
class SetStatusHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service and the configured schemas.
	 * @param StatusTypeLookup $statuses       Resolves a status name to its id within a case type.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTypeLookup $statuses,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action id this handler answers to.
	 *
	 * @return string The action type.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function type(): string {
		return 'setStatus';
	}//end type()

	/**
	 * Move the case to the named status.
	 *
	 * @param array $actionConfig       `{type: 'setStatus', status: '<name>'}`.
	 * @param array $case               The case being walked.
	 * @param array $transitionContext  The surrounding transition's context.
	 *
	 * @return ActionResult Success with the resolved status, or a named failure.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$statusName = trim((string)($actionConfig['status'] ?? ''));
			if ($statusName === '') {
				return new ActionResult(succeeded: false, error: 'set_status_missing_status');
			}

			$caseTypeId = (string)($case['caseType'] ?? '');
			if ($caseTypeId === '') {
				return new ActionResult(succeeded: false, error: 'case_has_no_case_type');
			}

			$statusId = $this->statuses->idForName(
				caseTypeId: $caseTypeId,
				statusName: $statusName
			);

			if ($statusId === '') {
				// Named, not generic: an operator reading the run history has to
				// be able to see WHICH status could not be found, because the
				// fix is almost always a missing status on the case type.
				$this->logger->warning(
					'Dossiq setStatus: the case type has no status named "' . $statusName . '"',
					['caseType' => $caseTypeId, 'case' => ($case['id'] ?? null)]
				);

				return new ActionResult(succeeded: false, error: 'status_not_found_on_case_type');
			}

			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'storage_unavailable');
			}

			$register = $this->settingsService->getConfigValue(key: 'register');
			$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
			if ($register === '' || $caseSchema === '') {
				return new ActionResult(succeeded: false, error: 'case_schema_not_configured');
			}

			$case['status'] = $statusId;
			$objectService->saveObject(object: $case, register: $register, schema: $caseSchema);

			return new ActionResult(
				succeeded: true,
				data: ['status' => $statusId, 'statusName' => $statusName]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SetStatusHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);

			return new ActionResult(succeeded: false, error: 'set_status_failed');
		}//end try
	}//end handle()
}//end class
