<?php

/**
 * Dossiq CasePhaseTermListener.
 *
 * A case moves to another phase, so the phase clock moves with it.
 *
 * It reconciles rather than compares: if the phase clock running on the case
 * belongs to a phase the case has left, it is stopped, and a clock is started
 * for the phase the case is in now. Reconciling means the listener is
 * idempotent and needs no before-image, so it is correct on a replay and on a
 * write that came from somewhere this app does not own.
 *
 * @listener-placement inline correctness — a phase clock measures how long a
 * case sat in a phase, so the moment it starts IS the measurement. Deferring
 * the start to a queue makes every phase read shorter than it was, by however
 * long the queue took, and the number this change exists to produce would be
 * quietly wrong rather than late. The work is bounded: one read of the case's
 * term instances and at most two writes on them.
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\PhaseTermService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\TermijnService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starting and stopping a phase clock as the case moves (REQ-TERM-061).
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see TermKind} is a vocabulary: four
 * constants and four pure predicates over an array, with no state, no I/O and
 * nothing to inject. Making it an instance would add a constructor dependency
 * to every class that names a kind, to hide a `::` behind a `->`.
 *
 * @template-implements IEventListener<Event>
 */
class CasePhaseTermListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param PhaseTermService $phases Starting and stopping phase clocks.
	 * @param TermijnService $termService The term instances on a case.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly PhaseTermService $phases,
		private readonly TermijnService $termService,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$case = $this->extractObject(event: $event);
			if ($case === null || $this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
				return;
			}

			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			$statusId = $this->reference(value: ($case['status'] ?? ''));
			$caseTypeId = $this->reference(value: ($case['caseType'] ?? ''));
			if ($caseId === '' || $statusId === '') {
				return;
			}

			if ($this->alreadyClocked(caseId: $caseId, statusTypeId: $statusId) === true) {
				return;
			}

			$this->phases->enterPhase(caseId: $caseId, caseTypeId: $caseTypeId, statusTypeId: $statusId);
		} catch (Throwable $e) {
			// A phase clock that could not start must never stop the case from
			// moving. The warning is the operator's signal that the phase
			// numbers on this case are incomplete.
			$this->logger->warning(
				'Dossiq phase term: the phase clock could not be moved with the case',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Whether the case already has a running clock for this phase.
	 *
	 * The guard that makes the listener idempotent: a case saved five times in
	 * one phase gets one clock, not five.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $statusTypeId The phase the case is in.
	 *
	 * @return bool True when a clock for this phase is already running.
	 */
	private function alreadyClocked(string $caseId, string $statusTypeId): bool {
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $row) {
			if (TermKind::ofInstance($row) !== TermKind::PHASE) {
				continue;
			}

			if ((string)($row['statusType'] ?? '') !== $statusTypeId) {
				continue;
			}

			if (in_array((string)($row['status'] ?? ''), ['lopend', 'verlengd', 'paused'], true) === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end alreadyClocked()

	/**
	 * One reference, whether it arrived as a uuid or as an expanded object.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['@self']['id'] ?? ''));
		}

		return trim((string)$value);
	}//end reference()

	/**
	 * Extract the OpenRegister object from an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The object, or null when there is none.
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end extractObject()
}//end class
