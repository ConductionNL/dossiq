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
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Resolves the ObjectService + register config.
	 * @param LoggerInterface $logger Logger.
	 * @param BackgroundServiceAccount $serviceAccount Writes for integriq's background work, which has no user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
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

			$this->projectOntoCase(delivery: $this->deliveryOf(event: $event, status: $status));
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

	/**
	 * Write the terminal delivery status onto the case's publication record.
	 *
	 * Matches the publication by the delivery correlation id — every
	 * conclusion originates from a dispatched request, which always carried
	 * one. Idempotent: re-delivering the same terminal state is a no-op
	 * write.
	 *
	 * @param ConcludedDelivery $delivery The case, the correlation id, the channel and the terminal outcome.
	 *
	 * @return void
	 */
	private function projectOntoCase(ConcludedDelivery $delivery): void {
		if ($delivery->caseId === '' || $delivery->correlationId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$this->projectWith(objectService: $objectService, delivery: $delivery);
	}//end projectOntoCase()

	/**
	 * Load the delivery's case, apply the outcome to its publication and save it.
	 *
	 * @param object            $objectService The OpenRegister ObjectService.
	 * @param ConcludedDelivery $delivery      The case, the correlation id, the channel and the terminal outcome.
	 *
	 * @return void
	 */
	private function projectWith(object $objectService, ConcludedDelivery $delivery): void {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->concludedCase(objectService: $objectService, delivery: $delivery, register: $register, schema: $schema);
		if ($case === null) {
			return;
		}

		$applied = $this->publicationsWith(case: $case, delivery: $delivery);
		if ($applied === null) {
			return;
		}

		$case['publications'] = $applied;
		$objectService->saveObject(object: $case, register: $register, schema: $schema);
	}//end projectWith()

	/**
	 * Load the delivery's case, logging when it is not found.
	 *
	 * @param object            $objectService The OpenRegister ObjectService.
	 * @param ConcludedDelivery $delivery      The concluded delivery.
	 * @param mixed             $register      The configured register id.
	 * @param mixed             $schema        The configured case schema id.
	 *
	 * @return array<string, mixed>|null The case data, or null when not found.
	 */
	private function concludedCase(object $objectService, ConcludedDelivery $delivery, mixed $register, mixed $schema): ?array {
		$case = $this->loadCase(objectService: $objectService, caseId: $delivery->caseId, register: $register, schema: $schema);
		if ($case === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: case not found for concluded delivery',
				['caseId' => $delivery->caseId, 'correlationId' => $delivery->correlationId]
			);
		}

		return $case;
	}//end concludedCase()

	/**
	 * The case's publications with the outcome on the matching delivery, or
	 * null when there is nothing to write.
	 *
	 * Nothing is written when the publications are not a list, when no
	 * publication carries the correlation id (logged), and when the matching
	 * delivery already has this terminal state (idempotent).
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param ConcludedDelivery    $delivery The outcome.
	 *
	 * @return array<int|string, mixed>|null The publications to save.
	 */
	private function publicationsWith(array $case, ConcludedDelivery $delivery): ?array {
		$publications = $case['publications'] ?? [];
		if (is_array($publications) === false) {
			return null;
		}

		$applied = $this->applyOutcome(publications: $publications, delivery: $delivery);
		if ($applied === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: no publication matches the concluded delivery',
				['caseId' => $delivery->caseId, 'correlationId' => $delivery->correlationId, 'channel' => $delivery->channel]
			);
			return null;
		}

		if ($applied === []) {
			// Idempotent: this terminal state is already projected.
			return null;
		}

		return $applied;
	}//end publicationsWith()

	/**
	 * Load a case from OpenRegister and normalise it to its array form.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $caseId The case id.
	 * @param mixed $register The configured register id.
	 * @param mixed $schema The configured case schema id.
	 *
	 * @return array<string, mixed>|null The case data, or null when not found.
	 */
	private function loadCase(object $objectService, string $caseId, mixed $register, mixed $schema): ?array {
		$obj = $objectService->find(id: $caseId, register: $register, schema: $schema);
		if ($obj === null) {
			return null;
		}

		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end loadCase()

	/**
	 * Apply a terminal delivery outcome to the matching publication entry.
	 *
	 * @param array<int, mixed> $publications The case's publications list.
	 * @param ConcludedDelivery $delivery The correlation id to match and the terminal outcome.
	 *
	 * @return array<int, mixed>|null The updated list; an empty array when the
	 *                                terminal state is already projected
	 *                                (idempotent no-op); null when nothing
	 *                                matches.
	 */
	private function applyOutcome(array $publications, ConcludedDelivery $delivery): ?array {
		foreach ($publications as $i => $pub) {
			if (is_array($pub) === false) {
				continue;
			}

			$stored = (array)($pub['delivery'] ?? []);
			if ((string)($stored['correlationId'] ?? '') !== $delivery->correlationId) {
				continue;
			}

			if ((string)($stored['status'] ?? '') === $delivery->outcome['status']) {
				return [];
			}

			$publications[$i]['delivery'] = array_merge($stored, $delivery->outcome);
			return $publications;
		}//end foreach

		return null;
	}//end applyOutcome()
}//end class
