<?php

/**
 * Dossiq DSO decision term: what a rung of its timer does.
 *
 * The assignee is told when the case enters the warning band, the critical
 * band, and when the term is overdue; on overdue the case is also marked
 * (`deadlineOverdue`) with one journal entry. This is what DsoDeadlineJob did
 * once a day; {@see DsoDeadlineTimer} now decides when. The case is read
 * FRESH before acting, so a case decided after the timer was armed is not
 * marked overdue.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the assignee and marks an overdue DSO case.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineActs {
	use SearchesObjects;

	/**
	 * Outcome: the assignee was told.
	 *
	 * @var string
	 */
	public const NOTIFIED = 'notified';

	/**
	 * Outcome: nothing to do (case gone, closed for DSO, or nobody assigned).
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Build the service.
	 *
	 * @param SettingsService      $settingsService Reaches OpenRegister and the case schema.
	 * @param INotificationManager $notifications   Sends the assignee's notification.
	 * @param CaseJournal          $journal         Writes the overdue journal entry.
	 * @param LoggerInterface      $logger          Logs a failed notification.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notifications,
		private readonly CaseJournal $journal,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the case fresh.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null The case, or null when it does not exist or nothing is configured.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function find(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
	}//end find()

	/**
	 * Tell the assignee the case entered a band.
	 *
	 * @param array<string, mixed> $case    The case, read fresh.
	 * @param string               $subject One of the Notifier's dso_deadline_* subjects.
	 *
	 * @return string NOTIFIED or SKIPPED.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function notify(array $case, string $subject): string {
		$caseId   = (string) ($case['id'] ?? '');
		$assignee = (string) ($case['assignee'] ?? '');
		if ($caseId === '' || $assignee === '' || $this->isOpen(case: $case) === false) {
			return self::SKIPPED;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(app: Application::APP_ID);
			$notification->setUser(user: $assignee);
			$notification->setSubject(subject: $subject, parameters: ['caseId' => $caseId]);
			$notification->setObject(type: 'case', id: $caseId);
			$notification->setDateTime(dateTime: new DateTime());
			$this->notifications->notify(notification: $notification);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq DSO: the deadline notification could not be sent: '.$e->getMessage(), ['case' => $caseId, 'subject' => $subject]);
			return self::SKIPPED;
		}

		return self::NOTIFIED;
	}//end notify()

	/**
	 * Mark the case overdue, once, and tell the assignee.
	 *
	 * @param array<string, mixed> $case The case, read fresh.
	 *
	 * @return bool True when the marker was written now.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function markOverdue(array $case): bool {
		if ($this->isOpen(case: $case) === false) {
			return false;
		}

		$this->notify(case: $case, subject: Notifier::SUBJECT_DSO_DEADLINE_OVERDUE);
		if (($case['deadlineOverdue'] ?? false) === true) {
			return false;
		}

		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return false;
		}

		$marked = $this->journal->append(
			case: $case,
			entry: ['type' => 'dsoDeadlineOverdue', 'note' => 'Wettelijke beslistermijn overschreden.'],
		);
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: (string) $case['id'],
			changes: [
				'deadlineOverdue'   => true,
				CaseJournal::FIELD => $marked[CaseJournal::FIELD],
			],
		);

		return true;
	}//end markOverdue()

	/**
	 * Whether the DSO term still runs on the case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool
	 */
	private function isOpen(array $case): bool {
		return in_array((string) ($case['dsoStatus'] ?? ''), DsoDeadlineTimer::OPEN_STATUSES, true);
	}//end isOpen()
}//end class
