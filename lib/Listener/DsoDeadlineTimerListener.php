<?php

/**
 * Dossiq DSO decision term timer listener.
 *
 * Keeps each DSO case's engine timer in step with the saved case: a case
 * whose DSO term starts running is armed, a moved deadline re-arms, a case
 * that leaves submitted/in_handling cancels. Cases are saved for every
 * reason, so a save that moved neither `dsoStatus` nor `deadlineDate` is
 * left alone. A case saved with its deadline already reached is marked
 * overdue at once, as DsoDeadlineJob would have on its next run.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the DSO timer on every case save that moved its status or deadline.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineTimerListener implements IEventListener {
	use SavedObjectPayload;

	/**
	 * The fields whose change moves the timer.
	 *
	 * @var string[]
	 */
	private const TIMED_FIELDS = ['dsoStatus', 'deadlineDate'];

	/**
	 * Build the listener.
	 *
	 * @param SettingsService  $settingsService Names the case schema.
	 * @param DsoDeadlineTimer $timer           Arms and cancels the timer.
	 * @param DsoDeadlineActs  $acts            Marks a case already overdue.
	 * @param LoggerInterface  $logger          Logs a failed sync.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DsoDeadlineTimer $timer,
		private readonly DsoDeadlineActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one saved object.
	 *
	 * @param Event $event An ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		try {
			$saved  = $this->savedObject(event: $event);
			$schema = (string) $this->settingsService->getConfigValue('case_schema');
			if ($saved === null
				|| $this->inSchema(object: $saved, configured: $schema) === false
				|| $this->unchanged(before: $this->previousObject(event: $event), after: $saved, fields: self::TIMED_FIELDS) === true
			) {
				return;
			}

			// A case that never had a DSO status is not a DSO case: no timer
			// to arm and none to cancel, so the engine is not asked.
			if (($saved['dsoStatus'] ?? null) === null && ($this->previousObject(event: $event)['dsoStatus'] ?? null) === null) {
				return;
			}

			if ($this->timer->sync(case: $saved) === DsoDeadlineTimer::DUE) {
				$this->acts->markOverdue(case: $saved);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq DSO: the DSO term timer could not be synced: '.$e->getMessage());
		}
	}//end handle()
}//end class
