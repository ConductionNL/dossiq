<?php

/**
 * Dossiq raiseDecision action handler.
 *
 * Action config shape: `{type: 'raiseDecision', decisionType: '<slug>'}`.
 * Hands the case to the decision app as a delegated decision of the
 * configured type, through ContractDecisionDelegationService, so the next
 * step (a contract, a decision in decidiq) starts from the case without new
 * code per process. The decision app answers with a decision reference,
 * which the result carries.
 *
 * A failed hand-off never rolls back the transition: the result says it
 * failed and the log says why, so the hand-off can be retried.
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

use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Kernel\Contracts\RaiseDecisionHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises a delegated decision of a configured type for the case.
 */
class RaiseDecisionHandler implements RaiseDecisionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param ContractDecisionDelegationService $decisions Raises the decision in the decision app
	 * @param LoggerInterface                            $logger    Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContractDecisionDelegationService $decisions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise the configured decision for the case.
	 *
	 * @param array<string, mixed> $actionConfig      The action: `decisionType`.
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The transition: `userId`, `transitionLabel`.
	 *
	 * @return ActionResult The decision reference, or why none was raised.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3-case-page
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return new ActionResult(succeeded: false, error: 'case_not_found');
		}

		$decisionType = trim((string)($actionConfig['decisionType'] ?? ''));
		if ($decisionType === '') {
			return new ActionResult(succeeded: false, error: 'decision_type_missing');
		}

		return $this->raise(
			request: ['caseId' => $caseId, 'decisionType' => $decisionType, 'caseTitle' => ($case['title'] ?? '')],
			transitionContext: $transitionContext
		);
	}//end handle()

	/**
	 * Raise the decision in decidiq and answer with its reference.
	 *
	 * @param array{caseId: string, decisionType: string, caseTitle: mixed}  $request           The case, the decision type and the case title.
	 * @param array<string, mixed>                                          $transitionContext The transition context (`userId`, `transitionLabel`).
	 *
	 * @return ActionResult The decision reference, or the failure.
	 */
	private function raise(array $request, array $transitionContext): ActionResult {
		try {
			$decisionRef = $this->decisions->raiseDecision(
				decisionType: $request['decisionType'],
				externalReference: $request['caseId'],
				subject: [
					'subjectRegister' => 'dossiq',
					'subjectSchema' => 'case',
					'subjectId' => $request['caseId'],
					'subjectLabel' => (string)$request['caseTitle'],
				],
				context: [
					'actorId' => (string)($transitionContext['userId'] ?? ''),
					'transition' => (string)($transitionContext['transitionLabel'] ?? ''),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'RaiseDecisionHandler: the decision could not be raised',
				['exception' => $e->getMessage(), 'case' => $request['caseId'], 'decisionType' => $request['decisionType']]
			);

			return new ActionResult(succeeded: false, error: 'raise_decision_failed');
		}//end try

		return new ActionResult(succeeded: true, data: ['decisionRef' => $decisionRef, 'decisionType' => $request['decisionType']]);
	}//end raise()
}//end class
