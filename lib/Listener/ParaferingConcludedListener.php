<?php

/**
 * Dossiq parafering-concluded listener.
 *
 * Consumes the decision app's terminal `ApprovalRouteConcludedEvent` and hands
 * the outcome to {@see \OCA\Dossiq\Service\Parafeer\ParaferingConclusionService},
 * which projects it onto the case file. The decision app runs the sign-off
 * chain; this listener is where dossiq hears that it finished — the parafering
 * twin of {@see DecisionConcludedListener}, and the counterpart of the raise
 * in ParaferingRaiseService.
 *
 * Filters strictly to `getSourceApp() === 'dossiq'` — the marker
 * ParaferingDelegationService stamped on the request, FROZEN there for exactly
 * this comparison (note: the DECISION seam froze `procest`; the two seams
 * predate each other's rename and each matches its own emitter). Events raised
 * for any other consuming app are ignored.
 *
 * Every field is read through duck-typed getters: the concrete event class is
 * the decision app's and absent from this app's autoload graph. Its own
 * failures are swallowed and logged so a defective projection never blocks
 * event delivery.
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
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Parafeer\ParaferingConclusionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the decision app's parafering conclusion on the voorstel.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
class ParaferingConcludedListener implements IEventListener {

	/**
	 * This app's source-app marker on the route request.
	 *
	 * FROZEN at `dossiq`: it must equal ParaferingDelegationService::SOURCE_APP
	 * or every conclusion is silently ignored — a mismatch is not an error,
	 * it is an event that looks like somebody else's.
	 */
	private const SOURCE_APP = 'dossiq';

	/**
	 * Constructor.
	 *
	 * @param ParaferingConclusionService $conclusions Projects the outcome onto the case file.
	 * @param LoggerInterface             $logger      Logger.
	 */
	public function __construct(
		private readonly ParaferingConclusionService $conclusions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a decision-app `ApprovalRouteConcludedEvent`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getSourceApp') === false || method_exists($event, 'getSubject') === false) {
			return;
		}

		try {
			if ((string)$event->getSourceApp() !== self::SOURCE_APP) {
				return;
			}

			$subject = (string)$event->getSubject();
			if ($subject === '') {
				return;
			}

			$this->conclusions->recordConclusion(
				proposalId: $subject,
				outcome: $this->readString(event: $event, getter: 'getOutcome'),
				actor: $this->readString(event: $event, getter: 'getActor'),
				actions: $this->readActions(event: $event),
			);
		} catch (Throwable $e) {
			// Never block event delivery on our own projection failure.
			$this->logger->warning(
				'Dossiq ParaferingConcludedListener: could not record the concluded chain: ' . $e->getMessage()
			);
		}//end try
	}//end handle()

	/**
	 * Read a duck-typed getter off the event as a string.
	 *
	 * @param Event $event The event.
	 * @param string $getter The zero-argument getter.
	 *
	 * @return string The value, or ''.
	 */
	private function readString(Event $event, string $getter): string {
		if (method_exists($event, $getter) === false) {
			return '';
		}

		$value = $event->$getter();
		if ($value === null || is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end readString()

	/**
	 * The sign-off record the event carries, when it carries one.
	 *
	 * An event from a decision app that predates the enriched payload answers
	 * no getActions(); the conclusion is still recorded — status, notification,
	 * audit — just without the per-step rows, and the recorder logs how many
	 * it wrote so the difference is visible.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<int, array<string, mixed>> The actions.
	 */
	private function readActions(Event $event): array {
		if (method_exists($event, 'getActions') === false) {
			return [];
		}

		$actions = $event->getActions();
		if (is_array($actions) === false) {
			return [];
		}

		return $actions;
	}//end readActions()

}//end class
