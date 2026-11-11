<?php

/**
 * Dossiq term: telling the case's handler that a term rung arrived.
 *
 * The term engine fires a rung on the 14/7/2/0 ladder of every running
 * statutory term, and the escalation service records it. Which of those
 * rungs a handler is TOLD about is configuration: the term definition lists
 * the days in `tellHandlerAt` (`[7, 0]` is "a week before the end, and when
 * it runs out"). A definition that lists nothing tells nobody, so a case type
 * that never asked for handler notices gets none.
 *
 * Generic on purpose (decision 182): any case type opts in by its term
 * definition. It replaced the procedure-specific daily job that warned a Woo
 * handler at T-7 and every day once overdue.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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

namespace OCA\Dossiq\Service\Termijn;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifies a case's assignee on the term rungs its definition names.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermHandlerNotice {
	use SearchesObjects;

	/**
	 * Build the service.
	 *
	 * @param SettingsService      $settingsService Reaches OpenRegister and the schemas.
	 * @param INotificationManager $notifications   Sends the notification.
	 * @param LoggerInterface      $logger          Logs a notice that could not go out.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Tell the case's handler that a rung arrived, when the definition asks for it.
	 *
	 * @param array<string, mixed> $instance  The term instance the rung fired on.
	 * @param int                  $threshold Days before the end the rung stands for; 0 is the end.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function tell(array $instance, int $threshold): bool {
		try {
			return $this->tellOrFail(instance: $instance, threshold: $threshold);
		} catch (Throwable $e) {
			// A statutory warning that could not go out is an error an operator
			// must see; the escalation itself is already recorded by the caller.
			$this->logger->error(
				'Dossiq termijn: the handler notice failed: '.$e->getMessage(),
				['case' => (string) ($instance['case'] ?? ''), 'threshold' => $threshold]
			);
			return false;
		}
	}//end tell()

	/**
	 * The notice itself; any read or send failure is thrown to tell().
	 *
	 * @param array<string, mixed> $instance  The term instance the rung fired on.
	 * @param int                  $threshold Days before the end the rung stands for; 0 is the end.
	 *
	 * @return bool True when a notification went out.
	 */
	private function tellOrFail(array $instance, int $threshold): bool {
		if ($this->rungIsNamed(instance: $instance, threshold: $threshold) === false) {
			return false;
		}

		$caseId   = (string) ($instance['case'] ?? '');
		$assignee = $this->assigneeOf(caseId: $caseId);
		if ($assignee === '') {
			// A case nobody holds has nobody to tell; the escalation itself is already recorded.
			return false;
		}

		$deadline = (string) ($instance['endDateCurrent'] ?? '');
		$this->send(assignee: $assignee, params: ['caseId' => $caseId, 'daysRemaining' => $threshold, 'deadline' => $deadline]);

		return true;
	}//end tellOrFail()

	/**
	 * Whether the term's definition tells the handler at this rung.
	 *
	 * @param array<string, mixed> $instance  The term instance the rung fired on.
	 * @param int                  $threshold Days before the end the rung stands for.
	 *
	 * @return bool True when the definition names the rung.
	 */
	private function rungIsNamed(array $instance, int $threshold): bool {
		$definition = $this->read(configKey: 'termijn_definitie_schema', id: (string) ($instance['deadlineDefinition'] ?? ''));

		return $definition !== null && in_array($threshold, $this->thresholdsOf(definition: $definition), true) === true;
	}//end rungIsNamed()

	/**
	 * The user who holds the case, '' when nobody does.
	 *
	 * @param string $caseId The case.
	 *
	 * @return string The assignee's user id.
	 */
	private function assigneeOf(string $caseId): string {
		$assignee = ($this->read(configKey: 'case_schema', id: $caseId)['assignee'] ?? null);
		if (is_string($assignee) === false) {
			return '';
		}

		return $assignee;
	}//end assigneeOf()

	/**
	 * Send the notification: the term ran out at rung 0, it is nearing otherwise.
	 *
	 * @param string                                                     $assignee Who is told.
	 * @param array{caseId: string, daysRemaining: int, deadline: string} $params   The subject parameters.
	 *
	 * @return void
	 */
	private function send(string $assignee, array $params): void {
		$subject = Notifier::SUBJECT_TERM_NEARING;
		if ($params['daysRemaining'] === 0) {
			$subject = Notifier::SUBJECT_TERM_EXCEEDED;
		}

		$notification = $this->notifications->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser($assignee)
			->setDateTime(new DateTime())
			->setObject('case', $params['caseId'])
			->setSubject($subject, $params);
		$this->notifications->notify($notification);
	}//end send()

	/**
	 * The days a definition tells the handler at, as integers.
	 *
	 * @param array<string, mixed> $definition The term definition.
	 *
	 * @return array<int, int> The thresholds; empty when the definition names none.
	 */
	private function thresholdsOf(array $definition): array {
		$declared = $definition['tellHandlerAt'] ?? [];
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_map('intval', array_filter($declared, 'is_numeric')));
	}//end thresholdsOf()

	/**
	 * Read one object of a configured schema.
	 *
	 * @param string $configKey The settings key naming the schema.
	 * @param string $id        The object id.
	 *
	 * @return array<string, mixed>|null The object, or null when absent or unreadable.
	 */
	private function read(string $configKey, string $id): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue($configKey);
		if ($objectService === null || $register === '' || $schema === '' || $id === '') {
			return null;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
	}//end read()
}//end class
