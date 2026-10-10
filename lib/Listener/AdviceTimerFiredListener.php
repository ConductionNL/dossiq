<?php

/**
 * Dossiq advice timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the advice timers
 * {@see \OCA\Dossiq\Service\Advice\AdviceTimer} arms: a `preBreach` rung
 * reminds the advisor, the `slaBreached` rung expires the request. Told
 * apart by the rung KEY, the one part of a fire the engine guarantees.
 *
 * Every app's timers fire through the same event, so a fire is acted on only
 * when the timer is dossiq's and carries the advice source. The fire comes
 * from the engine's cron worker with nobody signed in, so the write runs as
 * the background service account, as AdviceDeadlineJob's did.
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

use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an advice timer's rungs into the reminder and the expiry.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class AdviceTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param AdviceService            $adviceService  Sends the reminder and expires the request.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly AdviceService $adviceService,
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

		$timer    = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		$adviceId = (string) ($metadata['adviceId'] ?? '');
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== AdviceTimer::METADATA_SOURCE
			|| $adviceId === ''
		) {
			return;
		}

		$rungKey = (string) $event->getRungKey();

		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: fn () => $this->act(adviceId: $adviceId, rungKey: $rungKey)
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq advice: timer fire handling failed',
				['advice' => $adviceId, 'rung' => $rungKey, 'error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The domain act for one rung.
	 *
	 * @param string $adviceId The request.
	 * @param string $rungKey  The rung key, `trigger:offset:unit`.
	 *
	 * @return void
	 */
	private function act(string $adviceId, string $rungKey): void {
		if (str_starts_with($rungKey, 'preBreach:') === true) {
			$this->adviceService->dispatchReminder($adviceId);
			return;
		}

		if (str_starts_with($rungKey, 'slaBreached:') === true) {
			$this->adviceService->expireAdvice($adviceId);
		}
	}//end act()
}//end class
