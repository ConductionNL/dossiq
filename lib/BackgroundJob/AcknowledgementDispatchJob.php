<?php

/**
 * Dossiq acknowledgement dispatch job.
 *
 * Sends the ontvangstbevestiging off the request thread, so an unreachable mail
 * transport never stops a case being created, and retries a failure rather than
 * dropping it.
 *
 * THE RETRY IS A RE-QUEUE, NOT A LOOP. `QueuedJob` runs once and is removed, so
 * a failed attempt queues the next one with the attempt number raised. Sleeping
 * inside the job would hold a background worker for the duration and still lose
 * the work if the worker is restarted.
 *
 * A SPENT RETRY BUDGET IS A STATE ON THE CASE. Not an error line: a statutory
 * duty somebody now has to perform by hand needs a case they can find, which is
 * what {@see AcknowledgementService::recordFailedAttempt()} writes.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AcknowledgementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Confirms receipt of one case, and queues the next attempt when it fails.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 */
class AcknowledgementDispatchJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time            The time factory.
	 * @param AcknowledgementService $acknowledgement The acknowledgement of receipt.
	 * @param IJobList               $jobList         The job list, for the next attempt.
	 * @param LoggerInterface        $logger          The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AcknowledgementService $acknowledgement,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Confirm receipt of one case.
	 *
	 * @param mixed $argument The job argument; expects `caseId` and `attempt`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			$this->logger->warning('Dossiq acknowledgement: the job was queued without an argument');

			return;
		}

		$caseId = (string)($argument['caseId'] ?? '');
		$attempt = max(1, (int)($argument['attempt'] ?? 1));

		if ($caseId === '') {
			$this->logger->warning('Dossiq acknowledgement: the job names no case');

			return;
		}

		try {
			$this->acknowledgement->acknowledge(caseId: $caseId, attempt: $attempt);
		} catch (RefusedException $e) {
			$this->fail(caseId: $caseId, attempt: $attempt, sentence: $e->getSentence());
		} catch (Throwable $e) {
			$this->fail(
				caseId: $caseId,
				attempt: $attempt,
				sentence: 'The acknowledgement of receipt could not be delivered.',
			);
			$this->logger->error(
				'Dossiq acknowledgement: delivery for case {case} threw',
				['case' => $caseId, 'attempt' => $attempt, 'reason' => $e->getMessage()],
			);
		}//end try
	}//end run()

	/**
	 * Record the failed attempt, and queue the next one when one is due.
	 *
	 * @param string  $caseId   The case UUID.
	 * @param integer $attempt  Which attempt failed.
	 * @param string  $sentence What went wrong, as one sentence.
	 *
	 * @return void
	 */
	private function fail(string $caseId, int $attempt, string $sentence): void {
		$again = $this->acknowledgement->recordFailedAttempt(
			caseId: $caseId,
			sentence: $sentence,
			attempt: $attempt,
		);

		if ($again === false) {
			return;
		}

		$this->jobList->add(
			self::class,
			['caseId' => $caseId, 'attempt' => ($attempt + 1)]
		);
	}//end fail()
}//end class
