<?php

/**
 * Dossiq StufIntegriqBridge.
 *
 * Dossiq no longer sends StUF itself (decision 193). An outbound message is
 * asked of integriq's StUF-ZDS case client with StufZdsCaseRequestedEvent, on
 * the integriq source the endpoint names in `integriqSource`; a confirmation
 * that still arrives on dossiq's wire-contract URL is handed to integriq with
 * StufZdsConfirmationReceivedEvent for one release. Both events are named as
 * strings: integriq is an optional runtime dependency, and with it disabled
 * nobody answers, which this bridge reports as unavailable, never as sent.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-outbound-stuf-goes-through-integriq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use OCA\Dossiq\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Asks integriq for StUF work.
 *
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-outbound-stuf-goes-through-integriq
 */
class StufIntegriqBridge {

	/**
	 * Integriq's ZDS case command.
	 *
	 * @var string
	 */
	private const REQUESTED_EVENT = 'OCA\Integriq\Event\StufZdsCaseRequestedEvent';

	/**
	 * Integriq's confirmation hand-over.
	 *
	 * @var string
	 */
	private const CONFIRMATION_EVENT = 'OCA\Integriq\Event\StufZdsConfirmationReceivedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Carries the events to integriq.
	 * @param LoggerInterface  $logger     The logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Ask integriq to send a vrij bericht on the endpoint's integriq source.
	 *
	 * @param array  $endpoint The stufEndpoint row.
	 * @param string $name     The template name.
	 * @param array  $payload  The template fields.
	 *
	 * @return array<string, mixed> Integriq's result: success, retrying, referentienummer, messageId, caseIdentification, fout.
	 *
	 * @throws StufException 400 when the endpoint names no integriq source or integriq refuses; 503 when integriq does not answer.
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-outbound-stuf-goes-through-integriq
	 */
	public function vrijBericht(array $endpoint, string $name, array $payload): array {
		$sourceId = trim((string)($endpoint['integriqSource'] ?? ''));
		if ($sourceId === '') {
			throw new StufException(message: 'The endpoint names no integriq source (integriqSource); nothing was sent.', code: Http::STATUS_BAD_REQUEST);
		}

		$eventClass = self::REQUESTED_EVENT;
		if (class_exists($eventClass) === false) {
			throw new StufException(message: 'Integriq is not installed; nothing was sent.', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$event = new $eventClass(
			sourceApp: Application::APP_ID,
			sourceId: $sourceId,
			operation: 'vrijBericht',
			arguments: ['name' => $name, 'payload' => $payload],
			correlationId: (string)($endpoint['id'] ?? '') . ':' . bin2hex(random_bytes(8))
		);
		$this->dispatcher->dispatchTyped($event);

		if ($event->isHandled() === false) {
			$this->logger->warning(message: 'StUF outbound: integriq did not answer the vrijBericht request');
			throw new StufException(message: 'Integriq did not answer; nothing was sent.', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$refusal = $event->getRefusal();
		if ($refusal !== null) {
			throw new StufException(message: $refusal, code: Http::STATUS_BAD_REQUEST);
		}

		return (array)$event->getResult();
	}//end vrijBericht()

	/**
	 * Hand a confirmation envelope to integriq and return its answer.
	 *
	 * @param string $rawXml The raw request body, unchanged.
	 *
	 * @return array{statusCode:int,body:string}|null Integriq's answer, or null when nobody answered.
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-on-the-old-url-reach-integriq
	 */
	public function forwardConfirmation(string $rawXml): ?array {
		$eventClass = self::CONFIRMATION_EVENT;
		if (class_exists($eventClass) === false) {
			return null;
		}

		$event = new $eventClass(sourceApp: Application::APP_ID, rawXml: $rawXml);
		$this->dispatcher->dispatchTyped($event);
		if ($event->isHandled() === false) {
			return null;
		}

		return ['statusCode' => (int)$event->getStatusCode(), 'body' => (string)$event->getBody()];
	}//end forwardConfirmation()
}//end class
