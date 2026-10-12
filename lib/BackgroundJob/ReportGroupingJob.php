<?php

/**
 * Places one new case in hermiq's report grouping, once, off the request.
 *
 * Runs as the background service account, because a queued job has no user
 * and OpenRegister refuses a write from nobody. Without a usable account the
 * argument goes back on the queue and nothing is read or sent.
 *
 * A failure to reach hermiq is logged and not retried: the case stands alone,
 * which is what it was before grouping existed, and the confirmations of
 * receipt owed never depended on the group.
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\Ai\ReportGroupingConsumer;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks hermiq once which group a new case belongs to.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
class ReportGroupingJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           The time factory.
	 * @param ReportGroupingConsumer   $grouping       Places the case.
	 * @param IJobList                 $jobList        The job list, to requeue without an account.
	 * @param LoggerInterface          $logger         The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the run reads and writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ReportGroupingConsumer $grouping,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Place one case.
	 *
	 * @param mixed $argument The job argument; expects `caseId`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
	 */
	protected function run($argument): void {
		$caseId = '';
		if (is_array($argument) === true) {
			$caseId = trim((string)($argument['caseId'] ?? ''));
		}

		if ($caseId === '') {
			$this->logger->warning('Dossiq report grouping: the job names no case');
			return;
		}

		try {
			$this->serviceAccount->runAs(operation: fn () => $this->place(caseId: $caseId));
		} catch (ServiceAccountUnavailableException $e) {
			$this->jobList->add(self::class, $argument);
		}
	}//end run()

	/**
	 * Place one case, as the service account.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return void
	 */
	private function place(string $caseId): void {
		try {
			$answer = $this->grouping->placeCase(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq report grouping: placing case {case} threw',
				['case' => $caseId, 'reason' => $e->getMessage()]
			);
			return;
		}

		if ($answer !== null && ($answer['declared'] ?? false) === true && ($answer['available'] ?? false) === false) {
			$this->logger->warning(
				'Dossiq report grouping: hermiq did not group case {case}: {reason}',
				['case' => $caseId, 'reason' => (string)($answer['reason'] ?? '')]
			);
		}
	}//end place()
}//end class
