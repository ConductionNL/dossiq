<?php

/**
 * Dossiq milestone stall: telling the assignee.
 *
 * What BottleneckDetectionJob did for each stalled case, now done once per
 * milestone when its stall timer breaches: the case is read fresh, the
 * milestone it waits on is asked again, and only when it is still the one
 * the timer was armed for and still late is the assignee told. A milestone
 * reached in the meantime, or a case closed, sends nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Milestone
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

namespace OCA\Dossiq\Service\Milestone;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies the assignee of a case stalled on a milestone.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class MilestoneStallActs {
	use SearchesObjects;

	/**
	 * Build the service.
	 *
	 * @param SettingsService      $settingsService Reaches OpenRegister and the case schema.
	 * @param StalledCaseDetector  $detector        Names the milestone the case waits on now.
	 * @param INotificationManager $notifications   Sends the notification.
	 * @param LoggerInterface      $logger          Logs a failed notification.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StalledCaseDetector $detector,
		private readonly INotificationManager $notifications,
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
	 * Tell the assignee, when the case still waits late on that milestone.
	 *
	 * @param array<string, mixed> $case       The case, read fresh.
	 * @param string               $identifier The milestone the timer was armed for; '' for any.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function notifyIfStalled(array $case, string $identifier = ''): bool {
		$row = $this->detector->waitingOn(case: $case);
		if ($row === null
			|| (int) ($row['daysOverdue'] ?? 0) <= 0
			|| ($identifier !== '' && (string) ($row['milestoneIdentifier'] ?? '') !== $identifier)
		) {
			return false;
		}

		$assignee = (string) ($row['assignee'] ?? '');
		$caseId   = (string) ($row['caseId'] ?? '');
		if ($assignee === '' || $caseId === '') {
			return false;
		}

		$label       = (string) ($row['milestoneLabel'] ?? '');
		$daysOverdue = (int) $row['daysOverdue'];

		try {
			$notification = $this->notifications->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($assignee)
				->setDateTime(new DateTime())
				->setObject('case', $caseId)
				->setSubject(Notifier::SUBJECT_MILESTONE_BOTTLENECK, ['milestone' => $label, 'daysOverdue' => $daysOverdue])
				->setMessage('plain', ['message' => 'Zaak wacht '.$daysOverdue.' dag(en) langer dan verwacht op mijlpaal "'.$label.'".']);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq milestone: the stall notification failed: '.$e->getMessage(), ['case' => $caseId]);
			return false;
		}

		return true;
	}//end notifyIfStalled()
}//end class
