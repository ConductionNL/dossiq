<?php

/**
 * Dossiq StufIntegriqBridge.
 *
 * Dossiq no longer sends StUF itself (decision 193). An outbound message is
 * asked of integriq's StUF-ZDS case client with StufZdsCaseRequestedEvent, on
 * the integriq source the endpoint names in `integriqSource`. Confirmations
 * and provider requests go to integriq's own URLs; dossiq no longer forwards
 * them. The event is named as a string: integriq is an optional runtime
 * dependency, and with it disabled
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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\StufIntegriqBridgeInterface;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Asks integriq for StUF work.
 *
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-outbound-stuf-goes-through-integriq
 */
class StufIntegriqBridge implements StufIntegriqBridgeInterface {

	/**
	 * Integriq's ZDS case command.
	 *
	 * @var string
	 */
	private const REQUESTED_EVENT = 'OCA\Integriq\Event\StufZdsCaseRequestedEvent';

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
		$event = $this->vrijBerichtEvent(endpoint: $endpoint, request: ['name' => $name, 'payload' => $payload]);
		$this->dispatcher->dispatchTyped($event);

		return $this->vrijBerichtAnswer(event: $event);
	}//end vrijBericht()

	/**
	 * The integriq request event that sends a vrijBericht through the endpoint's source.
	 *
	 * @param array<string, mixed>                               $endpoint The endpoint, with its integriq source.
	 * @param array{name: string, payload: array<string, mixed>} $request  The message name and its payload.
	 *
	 * @return object The event, not yet dispatched.
	 *
	 * @throws StufException When the endpoint names no source, or integriq is not installed.
	 */
	private function vrijBerichtEvent(array $endpoint, array $request): object {
		$sourceId = trim((string)($endpoint['integriqSource'] ?? ''));
		if ($sourceId === '') {
			throw new StufException(message: 'The endpoint names no integriq source (integriqSource); nothing was sent.', code: Http::STATUS_BAD_REQUEST);
		}

		$eventClass = self::REQUESTED_EVENT;
		if (class_exists($eventClass) === false) {
			throw new StufException(message: 'Integriq is not installed; nothing was sent.', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new $eventClass(
			sourceApp: AppIdentity::APP_ID,
			sourceId: $sourceId,
			operation: 'vrijBericht',
			arguments: ['name' => $request['name'], 'payload' => $request['payload']],
			correlationId: (string)($endpoint['id'] ?? '') . ':' . bin2hex(random_bytes(8))
		);
	}//end vrijBerichtEvent()

	/**
	 * What integriq answered to a dispatched vrijBericht request.
	 *
	 * @param object $event The dispatched request event.
	 *
	 * @return array<string, mixed> The result integriq gave.
	 *
	 * @throws StufException When integriq did not answer or refused the message.
	 */
	private function vrijBerichtAnswer(object $event): array {
		if ($event->isHandled() === false) {
			$this->logger->warning(message: 'StUF outbound: integriq did not answer the vrijBericht request');
			throw new StufException(message: 'Integriq did not answer; nothing was sent.', code: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$refusal = $event->getRefusal();
		if ($refusal !== null) {
			throw new StufException(message: $refusal, code: Http::STATUS_BAD_REQUEST);
		}

		return (array)$event->getResult();
	}//end vrijBerichtAnswer()
}//end class
