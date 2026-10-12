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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Cases\ConnectedSystemCaseWriter;
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
	 * @param ConnectedSystemCaseWriter $writer   Carries out a write as the service account.
	 */
	public function __construct(
		private readonly StufRegisterAccess $register,
		private readonly ConnectedSystemCaseWriter $writer,
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
		$isCase     = ($event instanceof \OCA\Integriq\Event\StufCaseMutationEvent);
		$isDocument = ($event instanceof \OCA\Integriq\Event\StufDocumentMutationEvent);
		if ($isCase === false && $isDocument === false) {
			return;
		}

		$sourceId = $event->getSourceId();
		if ($sourceId === '') {
			return;
		}

		$endpoint = $this->register->findOne(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: ['integriqSource' => $sourceId]);
		if ($endpoint === null) {
			return;
		}

		// An integriq from before decision 206 sends StUF fields and no operation: refuse, never guess from them.
		$operation = [];
		if (method_exists($event, 'getOperation') === true) {
			$operation = (array)$event->getOperation();
		}

		if ($operation === []) {
			$event->refuse(appId: Application::APP_ID, reason: 'Dossiq neemt wijzigingen alleen aan als bewerking van integriq.');
			return;
		}

		$outcome = $this->writer->carryOut(
			operation: $operation,
			connection: [
				'acceptsWrites' => (($endpoint['acceptsWrites'] ?? false) === true),
				'caseTypes' => (array)($endpoint['zaaktypeMappings'] ?? []),
				'documentTypes' => (array)($endpoint['documentTypeMappings'] ?? []),
			],
			system: $sourceId
		);
		if (isset($outcome['refusal']) === true) {
			$event->refuse(
				appId: Application::APP_ID,
				reason: (string)$outcome['refusal'],
				kind: (string)($outcome['kind'] ?? ConnectedSystemCaseWriter::REFUSED)
			);
			return;
		}

		$event->accept(appId: Application::APP_ID, identification: (string)($outcome['identifier'] ?? ''));
	}//end handle()
}//end class
