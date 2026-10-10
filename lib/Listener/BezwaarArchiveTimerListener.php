<?php

/**
 * Dossiq bezwaartermijn timer listener.
 *
 * Keeps each bezwaar trigger's engine timer in step with the saved trigger:
 * a new trigger is armed, a moved bekendmaking or archive date re-arms, a
 * trigger switched off cancels. A save that moved none of those is left
 * alone. A trigger saved with its archive date already reached is handed to
 * {@see BezwaarArchiveTrigger} at once, as BezwaarTermijnJob would have on
 * its next run.
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
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the bezwaartermijn timer on every save that moved its dates or switch.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class BezwaarArchiveTimerListener implements IEventListener {
	use SavedObjectPayload;

	/**
	 * The fields whose change moves the timer.
	 *
	 * @var string[]
	 */
	private const TIMED_FIELDS = ['announcementDate', 'archiveDate', 'archiveTriggerActive'];

	/**
	 * Build the listener.
	 *
	 * @param SettingsService       $settingsService Names the watched schema.
	 * @param BezwaarArchiveTimer   $timer           Arms and cancels the timer.
	 * @param BezwaarArchiveTrigger $trigger         Acts on a trigger already due.
	 * @param LoggerInterface       $logger          Logs a failed sync.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BezwaarArchiveTimer $timer,
		private readonly BezwaarArchiveTrigger $trigger,
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
			$schema = (string) $this->settingsService->getConfigValue(BezwaarArchiveTrigger::SCHEMA_CONFIG_KEY);
			if ($saved === null
				|| $this->inSchema(object: $saved, configured: $schema) === false
				|| $this->unchanged(before: $this->previousObject(event: $event), after: $saved, fields: self::TIMED_FIELDS) === true
			) {
				return;
			}

			if ($this->timer->sync(trigger: $saved) === BezwaarArchiveTimer::DUE) {
				$this->trigger->process(trigger: $saved);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq bezwaar: the bezwaartermijn timer could not be synced: '.$e->getMessage());
		}
	}//end handle()
}//end class
