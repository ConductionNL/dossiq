<?php

/**
 * Dossiq take-back timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the timers
 * {@see TakeBackWindow} arms: on the breach it asks
 * {@see CaseRouter::takeBack()} to hand the case back to its pool, which
 * reads the case fresh and does nothing when somebody accepted it in the
 * meantime. Runs as the background service account.
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Routing\CaseRouter;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a take-back breach into the case going back to its pool.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class TakeBackTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param CaseRouter               $router         Takes the case back.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly CaseRouter $router,
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
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		$timer    = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		$caseId   = (string) ($metadata['caseId'] ?? '');
		$routedTo = (string) ($metadata['routedTo'] ?? '');
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== TakeBackWindow::METADATA_SOURCE
			|| $caseId === ''
			|| $routedTo === ''
			|| str_starts_with((string) $event->getRungKey(), 'slaBreached:') === false
		) {
			return;
		}

		$routedAt = (string) ($metadata['routedAt'] ?? '');

		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: function () use ($caseId, $routedTo, $routedAt): void {
					$this->router->takeBack(caseId: $caseId, routedTo: $routedTo, routedAt: $routedAt);
				}
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq routing: take-back handling failed', ['case' => $caseId, 'error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
