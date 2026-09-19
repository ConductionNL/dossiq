<?php

/**
 * Open the first holding of a case the moment the case exists.
 *
 * Without this the chain would start at the first MOVE, which is a chain that
 * begins halfway through and reads as though nobody held the case before it
 * changed hands. The backfill fixes that for cases that already existed when
 * this change shipped; this listener is what stops the same hole opening again
 * for every case created afterwards.
 *
 * A pure observer (ADR-022): every decision lives in
 * {@see \OCA\Dossiq\Service\Custody\CaseCustodyChain}, and `begin()` is a
 * no-op on a case that already has an open holding, so a replayed event costs
 * one read and writes nothing.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A new case starts its chain of custody where it was registered.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class CustodyCaseCreatedListener implements IEventListener {

	/**
	 * The reason written on the holding a new case opens with.
	 *
	 * @var string
	 */
	public const REASON = 'Registered';

	/**
	 * Constructor.
	 *
	 * @param CaseCustodyChain         $custody      The chain the holding is written into.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface          $logger       Records a chain that could not be started.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseCustodyChain $custody,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$caseId = trim((string)($payload['id'] ?? ($payload['uuid'] ?? '')));
		if ($caseId === '') {
			return;
		}

		try {
			$this->custody->begin(
				caseId: $caseId,
				organisationUnit: trim((string)($payload['assignedGroup'] ?? '')),
				handler: trim((string)($payload['assignee'] ?? '')),
				from: $this->startOf(payload: $payload),
				reason: self::REASON,
				movedBy: trim((string)($payload['createdBy'] ?? '')),
			);
		} catch (Throwable $e) {
			// NOT debug. A case whose chain never started is a case whose
			// custody question cannot be answered later, and the only moment
			// anybody can notice is this one.
			$this->logger->warning(
				'Dossiq custody: a new case did not get its first holding',
				['case' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end handle()

	/**
	 * When the case started, as well as the payload can say.
	 *
	 * @param array<string, mixed> $payload The created case.
	 *
	 * @return string The moment, or an empty string so the chain uses now.
	 */
	private function startOf(array $payload): string {
		foreach (['startDate', 'registrationDate', 'requestedDate'] as $key) {
			$value = trim((string)($payload[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$self = ($payload['@self'] ?? []);
		if (is_array($self) === true) {
			return trim((string)($self['created'] ?? ''));
		}

		return '';
	}//end startOf()

	/**
	 * The created object as an array, however the event carries it.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The payload.
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
