<?php

/**
 * Dossiq advice timer listener.
 *
 * Keeps each advice request's engine timer in step with the saved request:
 * a new request is armed, a moved deadline re-arms, a received or expired
 * request cancels. Listening to the saved object rather than to the
 * service's own writes catches every path a request is written by, the
 * manifest's create included, which never passes through AdviceService.
 *
 * A save that moved neither the status nor the deadline is left alone, so a
 * note or a document on the request does not cancel and re-arm its timer.
 * A request saved with a deadline already past is expired at once, which is
 * what AdviceDeadlineJob did on its next run.
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
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the advice timer on every save that moved its status or deadline.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class AdviceTimerListener implements IEventListener {
	use SavedObjectPayload;

	/**
	 * The config key naming the adviesAanvraag schema.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'advies_aanvraag_schema';

	/**
	 * Build the listener.
	 *
	 * @param SettingsService $settingsService Names the watched schema.
	 * @param AdviceTimer     $timer           Arms and cancels the timer.
	 * @param AdviceService   $adviceService   Expires a request already overdue.
	 * @param LoggerInterface $logger          Logs a failed sync.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AdviceTimer $timer,
		private readonly AdviceService $adviceService,
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
			$advice = $this->savedObject(event: $event);
			if ($advice === null
				|| $this->inSchema(object: $advice, configured: (string) $this->settingsService->getConfigValue(self::SCHEMA_CONFIG_KEY)) === false
				|| $this->unchanged(before: $this->previousObject(event: $event), after: $advice, fields: ['status', 'deadline']) === true
			) {
				return;
			}

			if ($this->timer->sync(advice: $advice) === AdviceTimer::OVERDUE) {
				$this->adviceService->expireAdvice((string) $advice['id']);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq advice: the advice timer could not be synced: '.$e->getMessage());
		}
	}//end handle()
}//end class
