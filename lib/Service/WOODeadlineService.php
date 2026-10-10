<?php

/**
 * Dossiq WOO Deadline Service
 *
 * Service for WOO (Wet open overheid) deadline calculation and extension.
 * Enforces the 4-week response deadline with a single optional 2-week
 * extension per WOO Art. 4.4 and emits T-7 warning notifications.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Termijn\WooTermExtension;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for WOO-mandated deadline calculation and tracking.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-4
 */
class WOODeadlineService {

	use SearchesObjects;

	/**
	 * WOO initial processing period in days (WOO Art. 4.4).
	 */
	private const INITIAL_PERIOD_DAYS = 28;

	/**
	 * Days before deadline at which T-7 warning is emitted.
	 */
	private const WARNING_THRESHOLD_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param INotificationManager $notificationManager Nextcloud notification manager
	 * @param LoggerInterface $logger Logger
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param WooTermExtension|null $extension The Woo extension over the term engine.
	 *        Absent, an extension is refused rather than written onto the case.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?WooTermExtension $extension = null,
	) {
	}//end __construct()

	/**
	 * Calculate the initial WOO deadline from the receipt date.
	 *
	 * @param string $receiptDate ISO 8601 date of receipt (e.g. '2026-05-01')
	 *
	 * @return array<string, string> Array with 'expectedResolution' (Y-m-d) and 'processingPeriod' (ISO 8601)
	 *
	 * @throws \InvalidArgumentException If the date is invalid
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	public function calculate(string $receiptDate): array {
		$receipt = $this->dates->parse($receiptDate, 'receiptDate');

		// Woo art. 4.4 names the term; Algemene termijnenwet art. 1 decides the
		// day it lands on, and the organisation's calendar says which days
		// those are.
		$raw = $receipt->modify('+' . self::INITIAL_PERIOD_DAYS . ' days');
		$deadline = ($this->timerService?->rollTermEndFor(date: $raw) ?? $raw);

		return [
			'expectedResolution' => $this->dates->formatCalendarDate($deadline),
			'processingPeriod' => 'P' . self::INITIAL_PERIOD_DAYS . 'D',
		];
	}//end calculate()

	/**
	 * Extend the case's Woo term once, by the case type's declared extension.
	 *
	 * Delegated to {@see WooTermExtension}, which moves the statutory term
	 * instance through the one extension path. A case without a statutory
	 * term, or a second extension, is refused there with the 409 the
	 * controller translates (REQ-WTR-003).
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array<string, mixed> The extension (caseId, previousDeadline, deadline, extensionReason,
	 *         countExtensions, termInstanceId) and whether the requester was told (noticeStatus,
	 *         noticeChannel, noticeReasonCode, noticeReason).
	 *
	 * @throws \InvalidArgumentException If the reason is empty
	 * @throws \RuntimeException When the term engine is not available
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	public function extendDeadline(string $caseId, string $reason): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A reason is required for deadline extension');
		}

		if ($this->extension === null) {
			throw new RuntimeException('The term engine is not available, so the Woo term cannot be extended');
		}

		return $this->extension->extend(caseId: $caseId, reason: $reason);
	}//end extendDeadline()

	/**
	 * Check case deadline and emit T-7 warning notifications.
	 *
	 * Should be called from a nightly background job. Emits a Nextcloud
	 * notification to the assigned behandelaar when exactly 7 days remain.
	 *
	 * @param string $caseId The case UUID
	 * @param string $handler The user ID of the behandelaar to notify
	 *
	 * @return array<string, mixed> Warning status with daysRemaining and isOverdue flags
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	public function checkAndWarn(string $caseId, string $handler): array {
		$resolved = $this->resolveWarningDeadline(caseId: $caseId);
		if ($resolved['deadline'] === null) {
			return ['warned' => false, 'reason' => $resolved['reason']];
		}

		$daysRemaining = $this->signedDaysUntil(deadline: $resolved['deadline']);

		$isOverdue = ($daysRemaining < 0);
		$warned = false;

		if ($daysRemaining === self::WARNING_THRESHOLD_DAYS || $isOverdue === true) {
			$this->sendDeadlineNotification(
				userId: $handler,
				caseId: $caseId,
				daysRemaining: $daysRemaining,
				isOverdue: $isOverdue,
			);
			$warned = true;
		}

		return [
			'caseId' => $caseId,
			'daysRemaining' => $daysRemaining,
			'isOverdue' => $isOverdue,
			'warned' => $warned,
		];
	}//end checkAndWarn()

	/**
	 * Load the case and resolve the deadline that the warning check operates on.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array{deadline: \DateTimeImmutable|null, reason: string} The parsed deadline, or null with the blocking reason
	 */
	private function resolveWarningDeadline(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['deadline' => null, 'reason' => 'OpenRegister unavailable'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		if (empty($register) === true || empty($caseSchema) === true) {
			return ['deadline' => null, 'reason' => 'Case schema not configured'];
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);
		if ($case === null) {
			return ['deadline' => null, 'reason' => 'Case not found'];
		}

		$caseData = (array)$case;

		// `deadline` is the one declared deadline: rolled, and following the
		// statutory term after an extension (REQ-WTR-001, REQ-WTR-003).
		$deadlineStr = ($caseData['deadline'] ?? null);

		if (empty($deadlineStr) === true) {
			return ['deadline' => null, 'reason' => 'No deadline set'];
		}

		$deadline = $this->dates->tryParse($deadlineStr);
		if ($deadline === null) {
			return ['deadline' => null, 'reason' => 'Invalid deadline format'];
		}

		return ['deadline' => $deadline, 'reason' => ''];
	}//end resolveWarningDeadline()

	/**
	 * Count the days between today and a deadline, negative once the deadline has passed.
	 *
	 * @param DateTimeImmutable $deadline The deadline to measure against
	 *
	 * @return int Days remaining, negative when overdue
	 */
	private function signedDaysUntil(DateTimeImmutable $deadline): int {
		$today = $this->dates->today();
		$daysRemaining = (int)$today->diff($deadline)->days;
		if ($today > $deadline) {
			return -$daysRemaining;
		}

		return $daysRemaining;
	}//end signedDaysUntil()

	/**
	 * Send a deadline notification to the behandelaar.
	 *
	 * @param string $userId The user to notify
	 * @param string $caseId The case UUID
	 * @param int $daysRemaining Days remaining (negative if overdue)
	 * @param bool $isOverdue Whether the deadline has passed
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	private function sendDeadlineNotification(
		string $userId,
		string $caseId,
		int $daysRemaining,
		bool $isOverdue,
	): void {
		try {
			$subject = 'woo_deadline_warning';
			if ($isOverdue === true) {
				$subject = 'woo_deadline_overdue';
			}

			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('woo_deadline', $caseId)
				->setSubject(
					$subject,
					['caseId' => $caseId, 'daysRemaining' => $daysRemaining]
				);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to send WOO deadline notification: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'caseId' => $caseId],
			);
		}//end try
	}//end sendDeadlineNotification()


}//end class
