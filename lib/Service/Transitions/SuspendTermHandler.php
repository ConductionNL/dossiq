<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Transitions
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Suspends the case's term, the counterpart of {@see ResumeTermHandler}.
 *
 * The same suspension the Termijn card's "Termijn opschorten" registers, through
 * DeadlinePauseService, so the case type's rules on suspending (whether it may,
 * for how long, for which declared reason) apply to a flow exactly as to a
 * handler. A refusal comes back as the result's error, never as a half-paused
 * term.
 *
 * Config: `durationDays` (required, positive), `rationale` (the note beside the
 * pause), `pauseReason` (the key of a reason the case type declares).
 *
 * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
 */
class SuspendTermHandler implements ActionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param TermijnService       $terms  Reads the case's term.
	 * @param DeadlinePauseService $pauses Registers the suspension.
	 * @param LoggerInterface      $logger The logger.
	 */
	public function __construct(
		private readonly TermijnService $terms,
		private readonly DeadlinePauseService $pauses,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Suspend the term of the case.
	 *
	 * @param array<string, mixed> $actionConfig      The step's config.
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The run's context.
	 *
	 * @return ActionResult The outcome.
	 *
	 * @spec openspec/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return new ActionResult(succeeded: false, error: 'case_not_found');
		}

		$days = (int)($actionConfig['durationDays'] ?? 0);
		if ($days <= 0) {
			return new ActionResult(succeeded: false, error: 'duration_required');
		}

		try {
			$instance = $this->terms->getTermijnInstanceForZaak($caseId);
			if ($instance === null) {
				return new ActionResult(succeeded: false, error: 'no_term_on_this_case');
			}

			if ((string)($instance['status'] ?? '') === 'paused') {
				return new ActionResult(succeeded: false, error: 'term_already_paused');
			}

			$paused = $this->pauses->registerPauze(
				termInstanceId: (string)($instance['id'] ?? ($instance['uuid'] ?? '')),
				durationDays: $days,
				rationale: (string)($actionConfig['rationale'] ?? ''),
				pauseReason: (string)($actionConfig['pauseReason'] ?? '')
			);

			return new ActionResult(
				succeeded: true,
				data: ['endDate' => (string)($paused['endDateCurrent'] ?? '')]
			);
		} catch (RefusedException $e) {
			return new ActionResult(succeeded: false, error: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error(
				'SuspendTermHandler: the term could not be suspended',
				['exception' => $e->getMessage(), 'case' => $caseId]
			);

			return new ActionResult(succeeded: false, error: 'suspend_term_failed');
		}//end try
	}//end handle()
}//end class
