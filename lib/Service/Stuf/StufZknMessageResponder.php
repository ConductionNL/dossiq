<?php

/**
 * Procest StUF-ZKN/BG inbound message responder.
 *
 * Owns one thing: given the StUF message element lifted out of an inbound SOAP
 * envelope, produce the SOAP response for it — a Bv01 bevestiging for the
 * kennisgevingen (zakLk01, edcLk01), an empty La01 antwoord for the vragen
 * (zakLv01, npsLv01), and a Fo01 foutbericht for anything else.
 *
 * Split out of {@see \OCA\Procest\Controller\StufController}, which carried the
 * whole inbound path — envelope parsing, message location, per-type handling and
 * the outbound admin REST surface — in one class of complexity 81.
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

use OCA\Procest\Service\StufFieldMappingService;
use OCA\Procest\Service\StufMessageBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use Psr\Log\LoggerInterface;

/**
 * Builds the SOAP response for one inbound StUF message element.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */
class StufZknMessageResponder {
	/**
	 * Default stuurgegevens for this Procest instance (zender).
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_ZENDER = [
		'organisation' => 'Procest',
		'applicatie' => 'Procest',
	];

	/**
	 * Constructor.
	 *
	 * @param StufResponseBuilder $responses The inbound response builder.
	 * @param StufFieldMappingService $mappingService The field mapping service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly StufResponseBuilder $responses,
		private readonly StufFieldMappingService $mappingService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the SOAP response for one StUF message element.
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return DataDisplayResponse The SOAP XML response.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function respond(\DOMElement $message): DataDisplayResponse {
		return match ($message->localName) {
			'zakLk01' => $this->handleZakLk01(message: $message),
			'zakLv01' => $this->handleZakLv01(message: $message),
			'npsLv01' => $this->handleNpsLv01(message: $message),
			'edcLk01' => $this->handleEdcLk01(message: $message),
			default => $this->handleUnknownMessage(messageType: (string)$message->localName),
		};
	}//end respond()

	/**
	 * Create a SOAP XML response.
	 *
	 * @param string $xml The XML content.
	 * @param int $statusCode The HTTP status code.
	 *
	 * @return DataDisplayResponse
	 *
	 * @phpstan-param \OCP\AppFramework\Http::STATUS_* $statusCode
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function soapResponse(string $xml, int $statusCode = Http::STATUS_OK): DataDisplayResponse {
		$response = new DataDisplayResponse($xml, $statusCode);
		$response->addHeader('Content-Type', 'text/xml; charset=utf-8');
		return $response;
	}//end soapResponse()

	/**
	 * Handle zakLk01 (case create/update) message.
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return DataDisplayResponse
	 */
	private function handleZakLk01(\DOMElement $message): DataDisplayResponse {
		// Extract mutatiesoort.
		$objectElements = $message->getElementsByTagName('object');
		if ($objectElements->length === 0) {
			$response = $this->responses->buildFo01(
				'StUF055',
				'Geen object element in zakLk01',
				'server',
				self::DEFAULT_ZENDER,
				[]
			);
			return $this->soapResponse(xml: $response);
		}

		$objectEl = $objectElements->item(0);
		$mutatiesoort = $message->getAttribute('mutatiesoort');

		// Extract basic fields.
		$stufFields = $this->extractFields(
			element: $objectEl,
			fieldNames: [
				'identificatie',
				'omschrijving',
				'notes',
				'startdatum',
				'endDate',
				'einddatumGepland',
				'uiterlijkeEinddatumAfdoening',
				'vertrouwelijkAanduiding',
			]
		);

		// Map to internal properties.
		$internalData = $this->mappingService->mapZknToInternal($stufFields);

		$this->logger->info(
			'Processed zakLk01 mutatiesoort={mutatiesoort}, identifier={id}',
			[
				'mutatiesoort' => $mutatiesoort,
				'id' => ($internalData['identifier'] ?? 'none'),
			]
		);

		// In a full implementation, create/update OpenRegister objects here.
		// For now, return a Bv01 confirmation.
		$response = $this->responses->buildBv01(
			self::DEFAULT_ZENDER,
			[],
			$this->extractStuurgegevensReferentienummer(message: $message)
		);

		return $this->soapResponse(xml: $response);
	}//end handleZakLk01()

	/**
	 * Handle zakLv01 (case query) message.
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return DataDisplayResponse
	 */
	private function handleZakLv01(\DOMElement $message): DataDisplayResponse {
		// Extract query criteria from gelijk element.
		$gelijkElements = $message->getElementsByTagName('gelijk');
		$criteria = [];

		if ($gelijkElements->length > 0) {
			$criteria = $this->extractFields(
				element: $gelijkElements->item(0),
				fieldNames: [
					'identificatie',
					'omschrijving',
					'startdatum',
				]
			);
		}

		$this->logger->info(
			'Processed zakLv01 query with {criteriaCount} criteria',
			['criteriaCount' => count($criteria)]
		);

		// In a full implementation, query OpenRegister and build zakLa01 response.
		// For now, return an empty zakLa01 response.
		$body = '<zkn:zakLa01 xmlns:zkn="' . StufMessageBuilder::NS_ZKN . '" '
			. 'xmlns:stuf="' . StufMessageBuilder::NS_STUF . '">';
		$body .= $this->responses->buildStuurgegevens(self::DEFAULT_ZENDER, []);
		$body .= '<zkn:antwoord/>';
		$body .= '</zkn:zakLa01>';

		return $this->soapResponse(xml: $this->responses->buildSoapEnvelope($body));
	}//end handleZakLv01()

	/**
	 * Handle npsLv01 (person query) message.
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return DataDisplayResponse
	 */
	private function handleNpsLv01(\DOMElement $message): DataDisplayResponse {
		$bsn = $this->extractBsn(message: $message);

		$this->logger->info(
			'Processed npsLv01 person query for BSN {bsn}',
			['bsn' => substr($bsn, 0, 3) . '***']
		);

		// In a full implementation, query OpenRegister for person data.
		// For now, return an empty npsLa01 response.
		$body = '<bg:npsLa01 xmlns:bg="' . StufMessageBuilder::NS_BG . '" '
			. 'xmlns:stuf="' . StufMessageBuilder::NS_STUF . '">';
		$body .= $this->responses->buildStuurgegevens(self::DEFAULT_ZENDER, []);
		$body .= '<bg:antwoord/>';
		$body .= '</bg:npsLa01>';

		return $this->soapResponse(xml: $this->responses->buildSoapEnvelope($body));
	}//end handleNpsLv01()

	/**
	 * Handle edcLk01 (document create/update) message.
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return DataDisplayResponse
	 */
	private function handleEdcLk01(\DOMElement $message): DataDisplayResponse {
		$this->logger->info('Processed edcLk01 document message');

		$response = $this->responses->buildBv01(
			self::DEFAULT_ZENDER,
			[],
			$this->extractStuurgegevensReferentienummer(message: $message)
		);

		return $this->soapResponse(xml: $response);
	}//end handleEdcLk01()

	/**
	 * Handle unknown message type.
	 *
	 * @param string $messageType The unknown message type.
	 *
	 * @return DataDisplayResponse
	 */
	private function handleUnknownMessage(string $messageType): DataDisplayResponse {
		$this->logger->warning('Unknown StUF message type: {type}', ['type' => $messageType]);

		$response = $this->responses->buildFo01(
			'StUF001',
			'Onbekend berichttype',
			'server',
			self::DEFAULT_ZENDER,
			[]
		);

		return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
	}//end handleUnknownMessage()

	/**
	 * Extract the BSN from an npsLv01 gelijk element (best-effort).
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return string The BSN, or the empty string when absent.
	 */
	private function extractBsn(\DOMElement $message): string {
		$gelijkEl = $message->getElementsByTagName('gelijk')->item(0);
		if ($gelijkEl instanceof \DOMElement === false) {
			return '';
		}

		$bsnElements = $gelijkEl->getElementsByTagName('bsn');
		if ($bsnElements->length === 0) {
			return '';
		}

		return ($bsnElements->item(0)->textContent ?? '');
	}//end extractBsn()

	/**
	 * Extract the referentienummer from a message's stuurgegevens (best-effort).
	 *
	 * @param \DOMElement $message The StUF message element.
	 *
	 * @return string The referentienummer (empty if absent).
	 */
	private function extractStuurgegevensReferentienummer(\DOMElement $message): string {
		$stuurgegevensEl = $message->getElementsByTagName('stuurgegevens')->item(0);
		if ($stuurgegevensEl instanceof \DOMElement === false) {
			return '';
		}

		$refElements = $stuurgegevensEl->getElementsByTagName('referentienummer');
		if ($refElements->length === 0 || $refElements->item(0) === null) {
			return '';
		}

		return ($refElements->item(0)->textContent ?? '');
	}//end extractStuurgegevensReferentienummer()

	/**
	 * Extract field values from a DOM element.
	 *
	 * @param \DOMElement|null $element The parent element.
	 * @param string[] $fieldNames The field names to extract.
	 *
	 * @return array<string, string> The extracted field values.
	 */
	private function extractFields(?\DOMElement $element, array $fieldNames): array {
		$result = [];

		if ($element === null) {
			return $result;
		}

		foreach ($fieldNames as $fieldName) {
			$elements = $element->getElementsByTagName($fieldName);
			if ($elements->length > 0 && $elements->item(0) !== null) {
				$result[$fieldName] = ($elements->item(0)->textContent ?? '');
			}
		}

		return $result;
	}//end extractFields()
}//end class
