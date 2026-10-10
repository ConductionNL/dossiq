<?php

/**
 * Dossiq WOO Deadline Service
 *
 * Service for WOO (Wet open overheid) deadline calculation and extension.
 * The Woo term is a statutory term like any other (one term engine, Ruben
 * 2026-10-09): its length and its one extension come from the seeded term
 * definition `td-woo-verzoek` (Woo art. 4.4), it counts from receipt and it is
 * rolled by the Algemene termijnenwet. This service keeps no clock of its own;
 * it asks the term engine, and it emits the T-7 warning.
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
 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

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
	 * The case type slug the Woo term definition binds to (`td-woo-verzoek`).
	 */
	private const WOO_CASE_TYPE = 'woo-verzoek';

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
	 * @param TermDefinitions|null $definitions What `td-woo-verzoek` declares, and the
	 *        end date it implies. Required for {@see calculate()}.
	 * @param DeadlineExtensionService|null $extensions The one extension path, with its
	 *        ceiling and its roll. Required for {@see extendDeadline()}.
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly ?TermDefinitions $definitions = null,
		private readonly ?DeadlineExtensionService $extensions = null,
	) {
	}//end __construct()

	/**
	 * The Woo deadline for a receipt date, as the term engine counts it.
	 *
	 * The length is the seeded definition's (`td-woo-verzoek`, 28 days), the
	 * count and the Algemene termijnenwet roll are the engine's. There is no
	 * constant here any more: a municipality that administers another
	 * definition gets its own answer, and the list and the case page agree
	 * with this one because they read the same term.
	 *
	 * @param string $receiptDate ISO 8601 date of receipt (e.g. '2026-05-01')
	 *
	 * @return array<string, string> `deadline` and its alias `expectedResolution` (Y-m-d),
	 *         and `processingPeriod` (ISO 8601)
	 *
	 * @throws \InvalidArgumentException If the date is invalid
	 * @throws RefusedException When no Woo term definition is administered
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function calculate(string $receiptDate): array {
		$receipt = $this->dates->parse($receiptDate, 'receiptDate');
		$definition = $this->wooDefinition();
		$days = (int)($definition['standardDurationDays'] ?? 0);
		$deadline = $this->dates->formatCalendarDate(
			$this->definitions->endDateFor(start: $receipt, days: $days, definitie: $definition)
		);

		return [
			'deadline' => $deadline,
			'expectedResolution' => $deadline,
			'processingPeriod' => 'P' . $days . 'D',
		];
	}//end calculate()

	/**
	 * The administered Woo term definition, or a refusal.
	 *
	 * @return array<string, mixed> The definition.
	 *
	 * @throws RefusedException When none is administered.
	 */
	private function wooDefinition(): array {
		$definition = $this->definitions?->activeFor(caseType: self::WOO_CASE_TYPE);
		if ($definition === null || $this->definitions === null) {
			throw new RefusedException(
				rule: 'woo-term-definition-missing',
				sentence: 'No term definition is set up for Woo requests, so their term cannot be counted.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $definition;
	}//end wooDefinition()

	/**
	 * Extend a Woo request's term once, by the case type's extension period.
	 *
	 * Woo art. 4.4 lid 2 allows one extension of at most two weeks; the length
	 * is the definition's `extensionCapacity`. The extension goes through the
	 * term engine on the case's statutory term:
	 * the new end is rolled there, the one-extension ceiling is the term
	 * definition's and is read from the term instance (so it holds whatever
	 * the case schema keeps), and the case's deadline follows the term. The
	 * reason is the `verleng` event's rationale. Nothing is written onto the
	 * case by this service.
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array<string, mixed> The new deadline, with `expectedResolution` kept as an alias
	 *
	 * @throws RefusedException When there is no term to extend, or the extension is refused
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
	 */
	public function extendDeadline(string $caseId, string $reason): array {
		if (trim($reason) === '') {
			throw new RefusedException(
				rule: 'woo-extension-reason-missing',
				sentence: 'A reason is required to extend the term of a Woo request.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$definition = $this->wooDefinition();
		$period = (int)($definition['extensionCapacity'] ?? 0);
		if ($period <= 0 || $this->extensions === null) {
			throw new RefusedException(
				rule: 'woo-extension-unavailable',
				sentence: 'The Woo term cannot be extended: no extension is declared, or the term engine is not available.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		// The ceiling (one extension, Woo art. 4.4 lid 2) is the term engine's,
		// and refuses with 409 and its own rule.
		// FROM THE ORIGINAL END, UNROLLED (Ruben, 2026-10-09). The two weeks run
		// from the last day of the first four, as counted, not from the day the
		// Algemene termijnenwet moved it to; the new end is then rolled.
		$extended = $this->extensions->extendStatutoryTermOfCase(
			caseId: $caseId,
			rationale: $reason,
			days: $period,
			baseOf: fn (array $term): string => $this->dates->formatCalendarDate(
				$this->definitions->countedEndDateFor(
					start: $this->dates->parse((string)($term['startDate'] ?? ''), 'startDate'),
					days: (int)($definition['standardDurationDays'] ?? 0),
					definitie: $definition
				)
			),
		);

		$term = $extended['instance'];
		$deadline = (string)($term['endDateCurrent'] ?? '');
		$this->logger->info(
			'WOO deadline extended for case ' . $caseId . ' to ' . $deadline,
			['app' => Application::APP_ID],
		);

		return [
			'caseId' => $caseId,
			'previousDeadline' => $extended['previous'],
			'deadline' => $deadline,
			'expectedResolution' => $deadline,
			'extensionReason' => $reason,
			'countExtensions' => (int)($term['countExtensions'] ?? 0),
			'extensionCount' => (int)($term['countExtensions'] ?? 0),
			'termInstanceId' => (string)($term['id'] ?? ''),
		] + (array)($extended['notice'] ?? []);
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
	 *
	 * @spec openspec/changes/one-term-engine/specs/woo-case-type/spec.md#requirement-woo-deadline-tracking-and-extension
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

		// `deadline` only. It follows the statutory term, extension included
		// (REQ-OTE-01); the undeclared `expectedResolution` is no longer written.
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
