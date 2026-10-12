<?php

/**
 * Dossiq waiting run resumer.
 *
 * Wakes the flow run that was suspended on a decision, once that decision
 * concludes. The run is matched on the decision reference one of its resume
 * slots records, never on the case alone: a case has several decisions in
 * its life, and a run waiting on the second must not be advanced by the first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resumes the flow run that waits on a concluded decision.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
class WaitingRunResumer {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface     $logger Logger.
	 * @param FlowRunMapper|null  $runs   Finds the runs suspended on a case; absent, no run is resumed.
	 * @param FlowRunService|null $runner Delivers the resume signal; absent, no run is resumed.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?FlowRunMapper $runs = null,
		private readonly ?FlowRunService $runner = null,
	) {
	}//end __construct()

	/**
	 * Wake the case flow that was waiting on this decision, if one was.
	 *
	 * 🔑 MATCHED ON THE decisionRef, NOT ON THE CASE. A case has several
	 * decisions in its life, and a run suspended on the SECOND one must not be
	 * advanced by the first concluding. Matching on the case would do exactly
	 * that — and would look correct, because the run does belong to that case.
	 *
	 * So the run is only signalled when one of its awaiting node slots records
	 * this very ref. A run waiting on a different decision is left suspended,
	 * which is the spec'd behaviour and not an omission.
	 *
	 * Never throws into the caller: a decision concludes whether or not a flow
	 * was listening, and a failure to resume must not undo the besluit that was
	 * already materialised above.
	 *
	 * @param string $caseId      The case the decision belongs to.
	 * @param string $decisionRef The concluded decision's id.
	 * @param string $status      The terminal status decidiq reported.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function resumeWaitingRun(string $caseId, string $decisionRef, string $status): void {
		if ($this->runs === null || $this->runner === null || trim($decisionRef) === '') {
			return;
		}

		try {
			$suspended = $this->runs->findSuspendedBySubject(subjectUuid: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not look up runs suspended on case ' . $caseId . ': ' . $e->getMessage()
			);
			return;
		}

		foreach ($suspended as $run) {
			if ($this->awaitsDecision(run: $run, decisionRef: $decisionRef) === false) {
				continue;
			}

			try {
				$this->runner->signal(
					run: $run,
					payload: [
						// Without a `decision` key the resume is a nudge and the
						// node suspends again — so the outcome is what makes
						// this an answer, and it is decidiq's word, not ours.
						'decision' => $status,
						'decisionRef' => $decisionRef,
						'caseId' => $caseId,
					]
				);

				$this->logger->info(
					'Dossiq: resumed flow run ' . (string)$run->getUuid() . ' on decision ' . $decisionRef
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: could not resume flow run on a concluded decision: ' . $e->getMessage(),
					['run' => $run->getUuid(), 'decisionRef' => $decisionRef]
				);
			}//end try

			return;
		}//end foreach
	}//end resumeWaitingRun()

	/**
	 * Whether a suspended run is waiting on this particular decision.
	 *
	 * Reads the per-node resume slots the requesting node wrote. A run holds one
	 * slot per node across its life, so the question is whether ANY slot names
	 * this ref — not whether the run belongs to the right case, which the
	 * caller has already established and which is not sufficient.
	 *
	 * @param object $run         The suspended run.
	 * @param string $decisionRef The concluded decision's id.
	 *
	 * @return boolean True when a slot records this ref.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	private function awaitsDecision(object $run, string $decisionRef): bool {
		$context = ($run->getContext() ?? []);
		if (is_array($context) === false) {
			return false;
		}

		$slots = ($context[FlowResumeState::CONTEXT_KEY] ?? []);
		if (is_array($slots) === false) {
			return false;
		}

		foreach ($slots as $slot) {
			if (is_array($slot) === false) {
				continue;
			}

			if (trim((string)($slot['decisionRef'] ?? '')) === trim($decisionRef)) {
				return true;
			}
		}

		return false;
	}//end awaitsDecision()
}//end class
