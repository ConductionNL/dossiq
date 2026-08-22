<?php

/**
 * Dossiq inbound StUF SOAP request dispatcher.
 *
 * Turns a raw inbound request body into a SOAP response: enforces the size
 * ceiling, parses the envelope with XXE/DTD protections, locates the StUF
 * message element inside the SOAP Body, and hands that element to
 * {@see StufZknMessageResponder}. Every refusal along the way is answered with
 * a SOAP Fault rather than an HTTP error page, because the caller is a
 * zaaksysteem that only speaks SOAP.
 *
 * Split out of {@see \OCA\Dossiq\Controller\StufController} so the controller
 * keeps only its HTTP surface.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Stuf
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
 * @spec openspec/specs/stuf-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Stuf;

use DOMDocument;
use OCA\Dossiq\Service\StufMessageBuilder;
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
	 * StUF-ZKN service: case operations.
	 *
	 * An INTERNAL discriminator. It never reaches the wire — the sending
	 * endpoint is resolved from the envelope's `zender`, not from this value,
	 * and the only other use is log context. That is what makes it safe to
	 * hold in English while the ZGW REST resource `zaken` (ZrcController,
	 * ZgwService) stays Dutch: the latter IS the statutory API path.
	 *
	 * @var string
	 */
	public const SERVICE_CASES = 'cases';

	/**
	 * StUF-BG service: person operations.
	 *
	 * @var string
	 */
	public const SERVICE_PERSONS = 'persons';

	/**
	 * Inbound body ceiling (2 MiB) — mitigates XML bomb / DoS.
	 */
	private const MAX_BODY_BYTES = 2097152;

	/**
	 * Constructor.
	 *
	 * @param StufResponseBuilder $responses The inbound response builder.
	 * @param StufZknMessageResponder $responder The per-message-type responder.
	 * @param StufEnvelopeInspector $inspector Resolves the sending endpoint and
	 *                                         verifies its WSSE UsernameToken.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StufResponseBuilder $responses,
		private readonly StufZknMessageResponder $responder,
		private readonly StufEnvelopeInspector $inspector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one inbound SOAP request body.
	 *
	 * @param string|false $rawBody The raw request body (false when unreadable).
	 * @param string $service The service type (SERVICE_CASES or SERVICE_PERSONS).
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

		$authFault = $this->authenticateSender(rawBody: $rawBody, service: $service);
		if ($authFault instanceof DataDisplayResponse) {
			return $authFault;
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
	 * Authenticate the sending zaaksysteem before any message is interpreted.
	 *
	 * WHY THIS EXISTS. `StufController::zaken()` and `::personen()` carry
	 * `#[PublicPage]` + `#[NoCSRFRequired]` — correctly, because the caller is a
	 * municipal zaaksysteem with no Nextcloud session — and until now that was
	 * the ONLY thing standing between an anonymous HTTP client and
	 * {@see StufZknMessageResponder::respond()}, which dispatches `zakLk01`
	 * (case create/update), `zakLv01` (case query), `npsLv01` (person query by
	 * BSN) and `edcLk01` (document create/update).
	 *
	 * Today every one of those four handlers is a stub that answers with an
	 * empty `zakLa01` / `npsLa01` / `Bv01` and reaches no storage, so nothing
	 * currently leaks — and that is exactly why the guard belongs here NOW.
	 * The handlers carry the comment "In a full implementation, query
	 * OpenRegister…"; the day someone writes that body, an unauthenticated
	 * caller would be reading zaken and persons by BSN. A missing check that is
	 * masked by an unfinished body is not a check.
	 *
	 * `inbound()` — the third public StUF route on this controller — already
	 * verifies a WSSE UsernameToken against the sending endpoint's stored
	 * credentials. This applies the same predicate to the other two, so all
	 * three inbound routes share one posture.
	 *
	 * FAIL-CLOSED IN BOTH DIRECTIONS: an envelope whose `stuf:zender` matches no
	 * configured `StufEndpoint` is refused, and {@see
	 * StufEnvelopeInspector::verifyWsse()} itself returns false when the matched
	 * endpoint has no username or no resolvable password in the vault. An
	 * unconfigured instance therefore refuses rather than admits.
	 *
	 * The refusal is a SOAP Fault, not an HTTP error page, for the same reason
	 * every other refusal in this class is: the caller only speaks SOAP. It
	 * deliberately does not distinguish "unknown sender" from "bad password",
	 * so the endpoint is not an oracle for which zaaksystemen are configured.
	 *
	 * @param string $rawBody The raw inbound envelope.
	 * @param string $service The service type (SERVICE_CASES or SERVICE_PERSONS).
	 *
	 * @return DataDisplayResponse|null A SOAP Fault when refused, null when the
	 *                                  sender is authenticated.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	private function authenticateSender(string $rawBody, string $service): ?DataDisplayResponse {
		$endpoint = $this->inspector->resolveEndpoint(envelopeXml: $rawBody);
		if ($endpoint === null) {
			$this->logger->warning(
				'StUF inbound refused at {service}: could not resolve a configured endpoint from the envelope zender',
				['service' => $service]
			);
			return $this->fault(
				message: 'Authentication failed',
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->inspector->verifyWsse(envelopeXml: $rawBody, endpoint: $endpoint) === false) {
			$this->logger->warning(
				'StUF inbound refused at {service}: WSSE signature mismatch for endpoint {id}',
				['service' => $service, 'id' => ($endpoint['id'] ?? '')]
			);
			return $this->fault(
				message: 'Authentication failed',
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		return null;
	}//end authenticateSender()

	/**
	 * Parse an inbound SOAP envelope with XXE/DTD protections.
	 *
	 * @param string $rawBody The raw request body.
	 * @param string $service The service type (SERVICE_CASES or SERVICE_PERSONS).
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
			return $this->fault(message: 'Invalid XML message');
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
