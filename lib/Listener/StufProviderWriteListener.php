<?php

/**
 * Dossiq StUF Provider Write Listener.
 *
 * Carries out the case and document writes integriq receives from a case
 * system over StUF (decision 206). Integriq owns the wire: it authenticates
 * the sender against its source and maps the message onto a generic operation.
 * Dossiq has no StUF write route of its own. This listener answers only for an
 * integriq source a stufEndpoint names in `integriqSource`, hands the operation
 * to {@see ConnectedSystemCaseWriter} with what that endpoint allows, and puts
 * the outcome back on the event.
 *
 * A store that fails throws out of this listener, so integriq answers a server
 * fault and never a confirmation.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-write-over-stuf-arrives-through-integriq-only
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ConnectedSystemCaseWriterInterface;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Carries out integriq's StUF write events on dossiq's cases.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-write-over-stuf-arrives-through-integriq-only
 */
class StufProviderWriteListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess        $register The stufEndpoint rows.
	 * @param ConnectedSystemCaseWriterInterface $writer   Carries out a write as the service account.
	 */
	public function __construct(
		private readonly StufRegisterAccess $register,
		private readonly ConnectedSystemCaseWriterInterface $writer,
	) {

	}//end __construct()

	/**
	 * Carry out, refuse, or stay silent when the source is not one of dossiq's.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-writes-through-integriq/specs/stuf-integration/spec.md#requirement-a-write-over-stuf-arrives-through-integriq-only
	 */
	public function handle(Event $event): void {
		// Integriq's classes, optional at runtime: instanceof on an absent class is false.
		if (($event instanceof \OCA\Integriq\Event\StufCaseMutationEvent) === false
			&& ($event instanceof \OCA\Integriq\Event\StufDocumentMutationEvent) === false
		) {
			return;
		}

		$endpoint = $this->endpointOf(event: $event);
		if ($endpoint === null) {
			return;
		}

		// A message integriq did not map onto an operation is refused, never guessed from its StUF fields.
		// An integriq from before decision 206 has no getOperation(): that call fails, integriq catches
		// it and answers a server fault, so no write is confirmed there either.
		if ($event->getOperation() === []) {
			$event->refuse(appId: AppIdentity::APP_ID, reason: 'Dossiq neemt wijzigingen alleen aan als bewerking van integriq.');
			return;
		}

		$this->carryOut(event: $event, endpoint: $endpoint);
	}//end handle()

	/**
	 * The endpoint configured for the message's source, or null when this app serves none.
	 *
	 * @param \OCA\Integriq\Event\StufCaseMutationEvent|\OCA\Integriq\Event\StufDocumentMutationEvent $event The mutation.
	 *
	 * @return array<string, mixed>|null
	 */
	private function endpointOf(\OCA\Integriq\Event\StufCaseMutationEvent|\OCA\Integriq\Event\StufDocumentMutationEvent $event): ?array {
		$sourceId = $event->getSourceId();
		if ($sourceId === '') {
			return null;
		}

		return $this->register->findOne(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: ['integriqSource' => $sourceId]);
	}//end endpointOf()

	/**
	 * Carry the operation out within what the endpoint allows, and answer the message.
	 *
	 * @param \OCA\Integriq\Event\StufCaseMutationEvent|\OCA\Integriq\Event\StufDocumentMutationEvent $event    The mutation.
	 * @param array<string, mixed> $endpoint The source's endpoint.
	 *
	 * @return void
	 */
	private function carryOut(\OCA\Integriq\Event\StufCaseMutationEvent|\OCA\Integriq\Event\StufDocumentMutationEvent $event, array $endpoint): void {
		$outcome = $this->writer->carryOut(
			operation: $event->getOperation(),
			connection: [
				'acceptsWrites' => (($endpoint['acceptsWrites'] ?? false) === true),
				'caseTypes' => (array)($endpoint['zaaktypeMappings'] ?? []),
				'documentTypes' => (array)($endpoint['documentTypeMappings'] ?? []),
			],
			system: $event->getSourceId()
		);
		if (isset($outcome['refusal']) === true) {
			$event->refuse(
				appId: AppIdentity::APP_ID,
				reason: (string)$outcome['refusal'],
				kind: (string)($outcome['kind'] ?? ConnectedSystemCaseWriterInterface::REFUSED)
			);
			return;
		}

		$event->accept(appId: AppIdentity::APP_ID, identification: (string)($outcome['identifier'] ?? ''));
	}//end carryOut()
}//end class
