<?php

/**
 * Procest inbound StUF SOAP request dispatcher.
 *
 * Turns a raw inbound request body into a SOAP response: enforces the size
 * ceiling, parses the envelope with XXE/DTD protections, locates the StUF
 * message element inside the SOAP Body, and hands that element to
 * {@see StufZknMessageResponder}. Every refusal along the way is answered with
 * a SOAP Fault rather than an HTTP error page, because the caller is a
 * zaaksysteem that only speaks SOAP.
 *
 * Split out of {@see \OCA\Procest\Controller\StufController} so the controller
 * keeps only its HTTP surface.
 *
 * @category Service
 * @package  OCA\Procest\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Stuf;

use DOMDocument;
use OCA\Procest\Service\StufMessageBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use Psr\Log\LoggerInterface;

/**
 * Parses an inbound StUF SOAP request and dispatches it to the responder.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */
class StufSoapRequestDispatcher {
	/**
	 * Inbound body ceiling (2 MiB) — mitigates XML bomb / DoS.
	 */
	private const MAX_BODY_BYTES = 2097152;

	/**
	 * Constructor.
	 *
	 * @param StufResponseBuilder $responses The inbound response builder.
	 * @param StufZknMessageResponder $responder The per-message-type responder.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StufResponseBuilder $responses,
		private readonly StufZknMessageResponder $responder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one inbound SOAP request body.
	 *
	 * @param string|false $rawBody The raw request body (false when unreadable).
	 * @param string $service The service type ('zaken' or 'personen').
	 *
	 * @return DataDisplayResponse The SOAP XML response.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function dispatch(string|false $rawBody, string $service): DataDisplayResponse {
		if ($rawBody === false || $rawBody === '') {
			return $this->fault(message: 'Leeg bericht ontvangen');
		}

		// Enforce size limit to mitigate XML bomb / DoS.
		if (strlen($rawBody) > self::MAX_BODY_BYTES) {
			return $this->fault(
				message: 'Bericht te groot',
				statusCode: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		$parsed = $this->parseSoapDocument(rawBody: $rawBody, service: $service);
		if ($parsed instanceof DataDisplayResponse) {
			return $parsed;
		}

		$messageElement = $this->extractStufMessageElement(dom: $parsed);
		if ($messageElement instanceof DataDisplayResponse) {
			return $messageElement;
		}

		$this->logger->info(
			'Received StUF message: {type} at {service}',
			['type' => $messageElement->localName, 'service' => $service]
		);

		return $this->responder->respond(message: $messageElement);
	}//end dispatch()

	/**
	 * Parse an inbound SOAP envelope with XXE/DTD protections.
	 *
	 * @param string $rawBody The raw request body.
	 * @param string $service The service type ('zaken' or 'personen').
	 *
	 * @return DOMDocument|DataDisplayResponse The parsed document, or a SOAP fault response.
	 */
	private function parseSoapDocument(string $rawBody, string $service): DOMDocument|DataDisplayResponse {
		// Parse the XML with XXE/DTD protections.
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		// LIBXML_NONET: prohibits network access from within XML (XXE via HTTP/FTP).
		// LIBXML_DTDLOAD: disabled intentionally (we do NOT load external DTDs).
		// Passing LIBXML_NOENT would *expand* entities — intentionally omitted.
		$parseResult = $dom->loadXML($rawBody, LIBXML_NONET);
		$errors = libxml_get_errors();
		libxml_clear_errors();

		if ($parseResult === false || empty($errors) === false) {
			$this->logger->warning('Invalid XML received at StUF endpoint: {service}', ['service' => $service]);
			return $this->fault(message: 'Ongeldig XML bericht');
		}

		return $dom;
	}//end parseSoapDocument()

	/**
	 * Locate the StUF message element inside a parsed SOAP envelope.
	 *
	 * @param DOMDocument $dom The parsed SOAP envelope.
	 *
	 * @return \DOMElement|DataDisplayResponse The StUF message element, or a SOAP fault response.
	 */
	private function extractStufMessageElement(DOMDocument $dom): \DOMElement|DataDisplayResponse {
		// Extract the SOAP Body content.
		$bodyElements = $dom->getElementsByTagNameNS(
			StufMessageBuilder::NS_SOAP,
			'Body'
		);

		if ($bodyElements->length === 0) {
			return $this->fault(message: 'Geen SOAP Body gevonden');
		}

		$body = $bodyElements->item(0);
		if ($body === null || $body->hasChildNodes() === false) {
			return $this->fault(message: 'Lege SOAP Body');
		}

		// Get the first child element (the StUF message).
		foreach ($body->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				return $child;
			}
		}

		return $this->fault(message: 'Geen StUF bericht element gevonden');
	}//end extractStufMessageElement()

	/**
	 * Build a SOAP Fault response.
	 *
	 * @param string $message The fault description.
	 * @param int $statusCode The HTTP status code.
	 *
	 * @return DataDisplayResponse
	 *
	 * @phpstan-param \OCP\AppFramework\Http::STATUS_* $statusCode
	 */
	private function fault(string $message, int $statusCode = Http::STATUS_BAD_REQUEST): DataDisplayResponse {
		return $this->responder->soapResponse(
			xml: $this->responses->buildSoapFault($message),
			statusCode: $statusCode
		);
	}//end fault()
}//end class
