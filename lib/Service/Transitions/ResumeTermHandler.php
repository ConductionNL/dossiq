<?php

/**
 * Dossiq resumeTerm action handler.
 *
 * Action config shape: `{type: 'resumeTerm'}`. It takes no configuration,
 * because there is nothing to choose: a case has one running term, it was
 * paused to ask the applicant for something, and finishing the task that
 * processed the aanvulling is what lifts the pause.
 *
 * WHY IT IS AN EFFECT AND NOT A STEP IN A HANDLER'S HEAD. The pause is
 * administered (`phase-terms-and-the-internal-target`, AWB 4:15) and the
 * resume moves a statutory deadline. Leaving it to somebody remembering is how
 * a term stays paused after the aanvulling arrived, and the case runs on a
 * deadline that is weeks too late in the municipality's favour.
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

use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Built-in handler for the `resumeTerm` task effect.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
class ResumeTermHandler implements ActionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param TermijnService       $terms  Finds the case's term instance.
	 * @param DeadlinePauseService $pauses Lifts the pause and re-projects the end date.
	 * @param LoggerInterface      $logger The logger.
	 */
	public function __construct(
		private readonly TermijnService $terms,
		private readonly DeadlinePauseService $pauses,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resume the term this case has paused.
	 *
	 * A case with no term, or one that is not paused, is not an error and is
	 * not silently ignored either: it answers a named refusal, which the
	 * caller records against the task that declared the effect. "The term was
	 * already running" and "the effect did not run" are different facts and a
	 * handler reading the case has to be able to tell them apart.
	 *
	 * @param array<string, mixed> $actionConfig      The action block.
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The dispatch context.
	 *
	 * @return ActionResult The outcome.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return new ActionResult(succeeded: false, error: 'case_not_found');
		}

		try {
			$instance = $this->terms->getTermijnInstanceForZaak($caseId);
			if ($instance === null) {
				return new ActionResult(succeeded: false, error: 'no_term_on_this_case');
			}

			if ((string)($instance['status'] ?? '') !== 'paused') {
				return new ActionResult(succeeded: false, error: 'term_not_paused');
			}

			$resumed = $this->pauses->resumeAfterPauze(
				termInstanceId: (string)($instance['id'] ?? ($instance['uuid'] ?? ''))
			);

			return new ActionResult(
				succeeded: true,
				data: ['endDate' => (string)($resumed['endDateCurrent'] ?? '')]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ResumeTermHandler: the term could not be resumed',
				['exception' => $e->getMessage(), 'case' => $caseId]
			);

			return new ActionResult(succeeded: false, error: 'resume_term_failed');
		}//end try
	}//end handle()
}//end class
