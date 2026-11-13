<?php

/**
 * Dossiq Termijn Notification Dispatch Job.
 *
 * Asynchronous QueuedJob that delivers a templated AWB termijn
 * notification (ontvangstbevestiging / extension / ingebrekestelling-
 * receipt / dwangsom-payment) outside the request thread, so SMTP /
 * berichtenbox-router failures never block burger-facing flows. The
 * synchronous {@see TermijnNotificationService::sendTermijnNotification}
 * remains the canonical render path; this job re-enters it from the
 * NC background-job runner.
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\TermijnNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Asynchronous queued notification dispatcher.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class DeadlineNotificationDispatchJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param TermijnNotificationService $notificationService Notification service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TermijnNotificationService $notificationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Execute one queued notification.
	 *
	 * @param mixed $argument Job argument; expects keys
	 *                        `type`, `termijnInstanceId`, `recipientUserId`, `context`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	protected function run($argument): void {
		$notice = $this->noticeOf(argument: $argument);
		if ($notice === null) {
			return;
		}

		try {
			$this->notificationService->sendTermijnNotification(
				$notice['type'],
				$notice['termInstanceId'],
				$notice['recipientUserId'],
				$notice['context'],
			);
			$this->logger->info(
				'DeadlineNotificationDispatchJob: delivered',
				['type' => $notice['type'], 'recipient' => $notice['recipientUserId'], 'instance' => $notice['termInstanceId']]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DeadlineNotificationDispatchJob: delivery failed',
				['error' => $e->getMessage(), 'type' => $notice['type'], 'recipient' => $notice['recipientUserId']]
			);
		}
	}//end run()

	/**
	 * The notification the job argument asks for, or null (with a warning) when it is incomplete.
	 *
	 * @param mixed $argument The queued job argument.
	 *
	 * @return array{type: string, termInstanceId: string, recipientUserId: string, context: array<mixed>}|null
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	private function noticeOf(mixed $argument): ?array {
		if (is_array($argument) === false) {
			$this->logger->warning('DeadlineNotificationDispatchJob: invalid argument');
			return null;
		}

		$notice = [
			'type' => (string)($argument['type'] ?? ''),
			'termInstanceId' => (string)($argument['termijnInstanceId'] ?? ''),
			'recipientUserId' => (string)($argument['recipientUserId'] ?? ''),
			'context' => (array)($argument['context'] ?? []),
		];
		if (in_array('', [$notice['type'], $notice['termInstanceId'], $notice['recipientUserId']], true) === true) {
			$this->logger->warning('DeadlineNotificationDispatchJob: missing required argument keys', $argument);
			return null;
		}

		return $notice;
	}//end noticeOf()
}//end class
