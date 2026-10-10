<?php

/**
 * Dossiq bezwaartermijn timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the timers
 * {@see BezwaarArchiveTimer} arms. On the breach rung it reads the trigger
 * FRESH (an objection may have come in after the timer was armed) and hands
 * it to {@see BezwaarArchiveTrigger}, which archives the beschikking or, with
 * an objection, only switches the trigger off. The fire comes from the
 * engine's cron worker with nobody signed in, so it writes as the background
 * service account, as BezwaarTermijnJob did.
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

use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a bezwaartermijn breach into the archive or the switch-off.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class BezwaarArchiveTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param BezwaarArchiveTrigger    $trigger        Reads and acts on the trigger.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly BezwaarArchiveTrigger $trigger,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one fire.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		$timer     = $event->getTimer();
		$metadata  = ($timer->getMetadata() ?? []);
		$triggerId = (string) ($metadata['triggerId'] ?? '');
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== BezwaarArchiveTimer::METADATA_SOURCE
			|| $triggerId === ''
			|| str_starts_with((string) $event->getRungKey(), 'slaBreached:') === false
		) {
			return;
		}

		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: function () use ($triggerId): void {
					$fresh = $this->trigger->find(triggerId: $triggerId);
					if ($fresh !== null) {
						$this->trigger->process(trigger: $fresh);
					}
				}
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar: timer fire handling failed',
				['trigger' => $triggerId, 'error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
