<?php

/**
 * Dossiq DeliveryConcluded listener.
 *
 * Consumes integriq's terminal `DeliveryConcludedEvent` (ADR-041 delivery
 * seam) and projects the outcome onto the case's publication record, so the
 * delivery status of a besluit publication is visible as case data. The
 * projection is local and idempotent, filtered to events this app raised
 * (`getSourceApp() === 'dossiq'`), and never advances anything on a
 * non-terminal outcome.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\ConcludedDelivery;
use OCA\Dossiq\Listener\Support\PublicationDeliveryProjection;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Projects integriq's terminal delivery outcome onto the case publication
 * record.
 *
 * @implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass -- registered via ListenerRegistrar by FQN string.
 *
 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
 */
class DeliveryConcludedListener implements IEventListener {
	/**
	 * Terminal statuses the seam can conclude with.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['delivered', 'abandoned'];

	/**
	 * Loads the case, records the outcome on its publication and saves it.
	 *
	 * @var PublicationDeliveryProjection
	 */
	private readonly PublicationDeliveryProjection $projection;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Resolves the ObjectService + register config.
	 * @param LoggerInterface $logger Logger.
	 * @param BackgroundServiceAccount $serviceAccount Writes for integriq's background work, which has no user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		$this->projection = new PublicationDeliveryProjection(settingsService: $settingsService, logger: $logger);
	}//end __construct()

	/**
	 * Handle an integriq `DeliveryConcludedEvent`.
	 *
	 * @param Event $event The dispatched event (integriq DeliveryConcludedEvent).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 */
	public function handle(Event $event): void {
		try {
			// Integriq raises this from its own background work, with nobody
			// signed in, and OpenRegister refuses a write from nobody. A
			// signed-in caller keeps writing as themselves.
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(operation: fn () => $this->answer(event: $event));
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// written; integriq's delivery record stays the source of truth,
			// and the case's publication shows the delivery as still requested.
			return;
		}
	}//end handle()

	/**
	 * Answer the event, as whoever is writing.
	 *
	 * @param Event $event The integriq event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delivers-nothing/specs/besluitvorming-delivery/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function answer(Event $event): void {
		// The event class is integriq's and optional at runtime; instanceof on
		// an absent class is simply false (no autoload error), and this
		// listener is only registered when the class exists. The declaration
		// stubs under tests/Stubs/Integriq keep the analyzers informed.
		if (($event instanceof \OCA\Integriq\Event\DeliveryConcludedEvent) === false) {
			return;
		}

		try {
			if ((string)$event->getSourceApp() !== AppIdentity::APP_ID) {
				return;
			}

			$status = strtolower((string)$event->getStatus());
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				return;
			}

			$this->projection->project(delivery: $this->deliveryOf(event: $event, status: $status));
		} catch (\Throwable $e) {
			// A projection failure must never bubble into integriq's delivery
			// bookkeeping — its message record stays the source of truth.
			$this->logger->error(
				'Dossiq DeliveryConcludedListener failed to project delivery outcome',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()]
			);
		}//end try
	}//end answer()

	/**
	 * The case, correlation id, channel and outcome a concluded delivery carries.
	 *
	 * @param \OCA\Integriq\Event\DeliveryConcludedEvent $event  The event.
	 * @param string                                     $status The terminal status, lower case.
	 *
	 * @return ConcludedDelivery The delivery.
	 */
	private function deliveryOf(\OCA\Integriq\Event\DeliveryConcludedEvent $event, string $status): ConcludedDelivery {
		return new ConcludedDelivery(
			caseId: (string)$event->getSubjectId(),
			correlationId: (string)$event->getCorrelationId(),
			channel: (string)$event->getChannel(),
			outcome: [
				'status' => $status,
				'attempts' => (int)$event->getAttempts(),
				'error' => $event->getError(),
				'concludedAt' => (string)$event->getConcludedAt(),
			]
		);
	}//end deliveryOf()
}//end class
