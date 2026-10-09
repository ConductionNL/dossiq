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
use OCA\Dossiq\Exception\ExtensionCeilingReachedException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for WOO-mandated deadline calculation and tracking.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The Woo deadline sits where
 * the case, its term instance, the extension ceiling and the notification meet.
 * The four exception and date types over the limit are value types, not
 * collaborators; the extension itself goes through TermijnService.
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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param INotificationManager $notificationManager Nextcloud notification manager
	 * @param LoggerInterface $logger Logger
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param TermijnService|null $termService The case's term instances. Required for an
	 *        extension; absent, an extension is refused rather than written onto the case.
	 * @param DeadlineExtensionService|null $extension The one extension path, which rolls
	 *        the date and holds the ceiling (REQ-WTR-003).
	 * @param TermDeclarationReader|null $declarations The case type's `extensionPeriod`.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermijnService $termService = null,
		private readonly ?DeadlineExtensionService $extension = null,
		private readonly ?TermDeclarationReader $declarations = null,
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
	 * Extend the Woo decision term of a case once, through the term engine.
	 *
	 * Woo art. 4.4 lid 2 allows one extension of at most two weeks. The new
	 * end is the term instance's current end plus the case type's
	 * `extensionPeriod` (P14D for the Woo case type), handed to
	 * `DeadlineExtensionService::requestExtension()`, which rolls it by the
	 * Algemene termijnenwet, enforces the definition's ceiling and records the
	 * reason as the `verleng` event's rationale. The case `deadline` follows
	 * the instance through the term write path. Nothing is written onto the
	 * case here: the keys this used to write (`expectedResolution`,
	 * `deadlineVerlengd`, `verdagingReden`) are not declared on the case
	 * schema, so the one-extension cap that read them back did not hold
	 * (REQ-WTR-003).
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array{caseId: string, previousDeadline: string, deadline: string, extensionReason: string, countExtensions: int}
	 *
	 * @throws \InvalidArgumentException If the reason is empty
	 * @throws RefusedException When the case has no statutory term or its extension is used up (409)
	 * @throws \RuntimeException When the term engine is not available
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function extendDeadline(string $caseId, string $reason): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A reason is required for deadline extension');
		}

		if ($this->termService === null || $this->extension === null) {
			throw new RuntimeException('The term engine is not available, so the Woo term cannot be extended');
		}

		$instance = $this->statutoryInstance(caseId: $caseId);
		if ($instance === null) {
			throw new RefusedException(
				rule: 'woo-term-missing',
				sentence: 'This case has no statutory term to extend.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$previous = substr((string)($instance['endDateCurrent'] ?? ''), 0, 10);
		$current = $this->dates->parse($previous, 'endDateCurrent');
		$newEnd = $current->modify('+' . $this->extensionPeriodDays(caseId: $caseId) . ' days');

		try {
			$updated = $this->extension->requestExtension(
				termInstanceId: (string)($instance['id'] ?? ''),
				rationale: $reason,
				newEndDate: $this->dates->formatCalendarDate($newEnd)
			);
		} catch (ExtensionCeilingReachedException $e) {
			throw new RefusedException(
				rule: 'woo-one-extension',
				sentence: 'This term was already extended. Woo art. 4.4 lid 2 allows one extension of at most two weeks.',
				status: RefusedException::STATUS_REFUSED,
				previous: $e,
			);
		}

		$deadline = substr((string)($updated['endDateCurrent'] ?? ''), 0, 10);

		$this->logger->info(
			'WOO deadline extended for case ' . $caseId . ' to ' . $deadline,
			['app' => Application::APP_ID],
		);

		return [
			'caseId' => $caseId,
			'previousDeadline' => $previous,
			'deadline' => $deadline,
			'extensionReason' => $reason,
			'countExtensions' => (int)($updated['countExtensions'] ?? 0),
		];
	}//end extendDeadline()

	/**
	 * The case's statutory term instance, newest first.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed>|null The instance, or null when the case has none.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TermKind::ofInstance() is a pure
	 * classifier over the instance array, with no state to inject.
	 */
	private function statutoryInstance(string $caseId): ?array {
		foreach ((array)$this->termService?->instancesForCase(caseId: $caseId) as $instance) {
			if (is_array($instance) === true && TermKind::ofInstance(instance: $instance) === TermKind::STATUTORY) {
				return $instance;
			}
		}

		return null;
	}//end statutoryInstance()

	/**
	 * The extension the case type declares, in days; the Woo two weeks when it declares none.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return int The extension period in days.
	 */
	private function extensionPeriodDays(string $caseId): int {
		$declared = (int)($this->declarations?->forCase(caseId: $caseId)['extensionPeriodDays'] ?? 0);
		if ($declared > 0) {
			return $declared;
		}

		return self::EXTENSION_PERIOD_DAYS;
	}//end extensionPeriodDays()

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
