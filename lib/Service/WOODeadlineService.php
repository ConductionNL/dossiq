<?php

/**
 * Procest WOO Deadline Service
 *
 * Service for WOO (Wet open overheid) deadline calculation and extension.
 * Enforces the 4-week response deadline with a single optional 2-week
 * extension per WOO Art. 4.4 and emits T-7 warning notifications.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
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
	 * WOO extension period in days (WOO Art. 4.4 verdaging).
	 */
	private const EXTENSION_PERIOD_DAYS = 14;

	/**
	 * Days before deadline at which T-7 warning is emitted.
	 */
	private const WARNING_THRESHOLD_DAYS = 7;

	/**
	 * Case property key tracking extension count.
	 */
	private const EXTENSION_COUNT_KEY = 'deadlineVerlengd';

	/**
	 * Case property key for extension reason.
	 */
	private const EXTENSION_REASON_KEY = 'verdagingReden';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param INotificationManager $notificationManager Nextcloud notification manager
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
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
		$receipt = $this->requireIsoDate(value: $receiptDate, label: 'ontvangstdatum');
		$deadline = $receipt->modify('+' . self::INITIAL_PERIOD_DAYS . ' days');

		return [
			'expectedResolution' => $deadline->format('Y-m-d'),
			'processingPeriod' => 'P' . self::INITIAL_PERIOD_DAYS . 'D',
		];
	}//end calculate()

	/**
	 * Extend the WOO deadline for a case by the statutory 14-day extension.
	 *
	 * Only one extension is allowed per WOO Art. 4.4 (verdaging).
	 * Updates the case object in OpenRegister with the new deadline and reason.
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array<string, mixed> Updated deadline info with new expectedResolution
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or case not found
	 * @throws \InvalidArgumentException If extension is not allowed or reason is empty
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	public function extendDeadline(string $caseId, string $reason): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A reason is required for deadline extension');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		if (empty($register) === true || empty($caseSchema) === true) {
			throw new RuntimeException('Case schema not configured');
		}

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);
		if ($case === null) {
			throw new RuntimeException('Case not found: ' . $caseId);
		}

		$caseData = (array)$case;

		$extensionCount = (int)($caseData[self::EXTENSION_COUNT_KEY] ?? 0);
		if ($extensionCount >= 1) {
			throw new InvalidArgumentException('Only one deadline extension is allowed per WOO Art. 4.4');
		}

		$currentDeadline = $caseData['expectedResolution'] ?? null;
		if (empty($currentDeadline) === true) {
			// Derive from ontvangstdatum if not set.
			$receiptDate = $caseData['ontvangstdatum'] ?? null;
			if (empty($receiptDate) === true) {
				throw new RuntimeException('Case has no ontvangstdatum to calculate deadline from');
			}

			$calculated = $this->calculate(receiptDate: $receiptDate);
			$currentDeadline = $calculated['expectedResolution'];
		}

		$deadline = $this->requireIsoDate(value: (string)$currentDeadline, label: 'expectedResolution');
		$newDeadline = $deadline->modify('+' . self::EXTENSION_PERIOD_DAYS . ' days');

		$updateData = array_merge(
			$caseData,
			[
				'expectedResolution' => $newDeadline->format('Y-m-d'),
				self::EXTENSION_COUNT_KEY => 1,
				self::EXTENSION_REASON_KEY => $reason,
			]
		);

		$objectService->saveObject(object: $updateData, register: $register, schema: $caseSchema, uuid: (string)$caseId);

		$this->logger->info(
			'WOO deadline extended for case ' . $caseId . ' to ' . $newDeadline->format('Y-m-d'),
			['app' => Application::APP_ID],
		);

		return [
			'caseId' => $caseId,
			'previousDeadline' => $currentDeadline,
			'expectedResolution' => $newDeadline->format('Y-m-d'),
			'extensionReason' => $reason,
			'extensionCount' => 1,
		];
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

		$deadlineStr = $caseData['expectedResolution'] ?? null;
		if (empty($deadlineStr) === true) {
			return ['deadline' => null, 'reason' => 'No deadline set'];
		}

		$deadline = $this->parseIsoDate(value: (string)$deadlineStr);
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
		$today = new DateTimeImmutable('today');
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

	/**
	 * Parse an ISO 8601 calendar date (Y-m-d) into an immutable date at midnight.
	 *
	 * Constructing the value directly (rather than through a static factory)
	 * keeps the parse honest: an unparseable value yields null instead of a
	 * boolean sentinel, and the resulting instant is midnight rather than the
	 * current wall-clock time, so day arithmetic is whole-day exact. A trailing
	 * time component is accepted and discarded — deadlines are calendar dates.
	 *
	 * @param string $value The date string to parse (e.g. '2026-05-01')
	 *
	 * @return \DateTimeImmutable|null The parsed date, or null when unparseable
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	private function parseIsoDate(string $value): ?DateTimeImmutable {
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable($parts[1] . '-' . $parts[2] . '-' . $parts[3] . ' 00:00:00');
		} catch (\Exception $e) {
			return null;
		}
	}//end parseIsoDate()

	/**
	 * Parse an ISO 8601 calendar date, rejecting an unparseable value.
	 *
	 * @param string $value The date string to parse (e.g. '2026-05-01')
	 * @param string $label The field name to name in the rejection message
	 *
	 * @return \DateTimeImmutable The parsed date at midnight
	 *
	 * @throws \InvalidArgumentException If the value is not a Y-m-d date
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	private function requireIsoDate(string $value, string $label): DateTimeImmutable {
		$parsed = $this->parseIsoDate(value: $value);
		if ($parsed === null) {
			throw new InvalidArgumentException('Invalid ' . $label . ': ' . $value);
		}

		return $parsed;
	}//end requireIsoDate()
}//end class
