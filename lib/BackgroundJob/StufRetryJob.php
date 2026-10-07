<?php

/**
 * Dossiq StufRetryJob.
 *
 * On-demand background job that retries an outbound StUF kennisgeving whose
 * previous attempt produced a transient failure. Scheduled by
 * StufAdapterService at exponential-backoff intervals (5s, 30s, 2m, 10m).
 * Each invocation reuses the SAME referentienummer for idempotency.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\Dossiq\Service\Stuf\StufAdapterService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use Psr\Log\LoggerInterface;

/**
 * On-demand background job that retries a single StufMessage.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
 */
class StufRetryJob extends Job {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param StufAdapterService $adapter The adapter service.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the retry writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private StufAdapterService $adapter,
		private LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Execute the retry.
	 *
	 * @param mixed $argument The job payload: {stufMessageId: string, runAt?: int}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
	 */
	protected function run(mixed $argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->work(argument: $argument));
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// read, sent or written; the next run tries again.
			return;
		}
	}//end run()

	/**
	 * The retry itself, run as the service account.
	 *
	 * @param mixed $argument The job payload: {stufMessageId: string, runAt?: int}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-circuit-breaker-and-retry
	 */
	private function work(mixed $argument): void {
		$payload = [];
		if (is_array(value: $argument) === true) {
			$payload = $argument;
		}

		$stufMessageId = (string)($payload['stufMessageId'] ?? '');
		$runAt = (int)($payload['runAt'] ?? 0);

		if ($stufMessageId === '') {
			$this->logger->warning(message: 'StufRetryJob: missing stufMessageId in payload');
			return;
		}

		if ($runAt > 0 && time() < $runAt) {
			// Re-defer: the cron picked us up early.
			return;
		}

		try {
			$this->adapter->retrySend(stufMessageId: $stufMessageId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: 'StufRetryJob failed for {id}: {error}',
				context: ['id' => $stufMessageId, 'error' => $e->getMessage()]
			);
		}
	}//end work()
}//end class
