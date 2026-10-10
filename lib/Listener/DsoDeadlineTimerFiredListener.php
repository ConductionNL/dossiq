<?php

/**
 * Dossiq DSO decision term timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the timers
 * {@see DsoDeadlineTimer} arms: the warning and critical rungs notify the
 * assignee, the breach marks the case overdue. Told apart by the rung's
 * trigger and offset unit (`preBreach:<n>:businessDays`), and the two bands by
 * which offset is the larger. The case is read FRESH, and the fire writes as
 * the background service account, as DsoDeadlineJob did.
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

use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a DSO term rung into the notification or the overdue marker.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param DsoDeadlineActs          $acts           Reads the case and acts on it.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly DsoDeadlineActs $acts,
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
		$caseId   = (string) ($metadata['caseId'] ?? '');
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== DsoDeadlineTimer::METADATA_SOURCE
			|| $caseId === ''
		) {
			return;
		}

		$message = (string) $event->getMessage();
		$rungKey = (string) $event->getRungKey();

		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: function () use ($caseId, $message, $rungKey): void {
					$case = $this->acts->find(caseId: $caseId);
					if ($case === null) {
						return;
					}

					$this->act(case: $case, message: $message, rungKey: $rungKey);
				}
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq DSO: timer fire handling failed', ['case' => $caseId, 'rung' => $rungKey, 'error' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The domain act for one rung.
	 *
	 * The band is read off the rung's message identity, which the timer
	 * sets per rung, because the two preBreach rungs differ only in offset
	 * and the offsets are settings.
	 *
	 * @param array<string, mixed> $case    The case, read fresh.
	 * @param string               $message The rung's message identity.
	 * @param string               $rungKey The rung key.
	 *
	 * @return void
	 */
	private function act(array $case, string $message, string $rungKey): void {
		if (str_starts_with($rungKey, 'slaBreached:') === true) {
			$this->acts->markOverdue(case: $case);
			return;
		}

		if ($message === DsoDeadlineTimer::MESSAGE_CRITICAL) {
			$this->acts->notify(case: $case, subject: Notifier::SUBJECT_DSO_DEADLINE_CRITICAL);
			return;
		}

		if ($message === DsoDeadlineTimer::MESSAGE_WARNING) {
			$this->acts->notify(case: $case, subject: Notifier::SUBJECT_DSO_DEADLINE_WARNING);
		}
	}//end act()
}//end class
