<?php

/**
 * Procest StUF response builder (inbound direction).
 *
 * Builds the SOAP messages procest RETURNS as a StUF receiver: the SOAP
 * envelope wrapper, the `stuf:`-prefixed stuurgegevens header, the Bv01
 * bevestiging, the Fo01 foutbericht and the plain SOAP Fault. All
 * DOMDocument-based and XXE-safe (`LIBXML_NONET`, no entity expansion).
 *
 * Split out of {@see \OCA\Procest\Service\StufMessageBuilder}, which owned both
 * directions and so exposed fourteen public methods. That class now owns the
 * OUTBOUND `zkn:`-namespaced request builders only; the two directions share no
 * caller — the controller only ever answers, the adapter only ever asks — and
 * they do not even share an XML style.
 *
 * The namespace constants stay on StufMessageBuilder: StufMessageParser
 * re-exports them from there, so they have one canonical home.
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

use DateTimeImmutable;
use DOMDocument;
use OCA\Procest\Service\StufMessageBuilder;

/**
 * Builds the StUF SOAP responses procest returns as a receiver.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/stuf-integration/spec.md
 */
class StufResponseBuilder {
	/**
	 * Build a complete SOAP envelope wrapping a StUF message body.
	 *
	 * @param string $bodyXml The StUF message body XML (without SOAP wrapper).
	 *
	 * @return string The complete SOAP envelope XML.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function buildSoapEnvelope(string $bodyXml): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$envelope = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Envelope');
		$envelope->setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:stuf',
			StufMessageBuilder::NS_STUF
		);
		$envelope->setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:zkn',
			StufMessageBuilder::NS_ZKN
		);
		$envelope->setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:bg',
			StufMessageBuilder::NS_BG
		);
		$envelope->setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:xsi',
			StufMessageBuilder::NS_XSI
		);
		$dom->appendChild($envelope);

		$header = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Header');
		$envelope->appendChild($header);

		$body = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Body');
		$envelope->appendChild($body);

		// M1: Load the caller-supplied body XML with LIBXML_NONET to prevent
		// XXE / SSRF attacks via external entity references in the XML payload.
		$bodyDoc = new DOMDocument();
		// phpcs:ignore -- libxml_use_internal_errors suppresses parse errors intentionally.
		libxml_use_internal_errors(true);
		if ($bodyDoc->loadXML($bodyXml, LIBXML_NONET) === true) {
			$imported = $dom->importNode($bodyDoc->documentElement, true);
			$body->appendChild($imported);
		}

		libxml_clear_errors();

		$saved = $dom->saveXML();
		if ($saved === false) {
			return '';
		}

		return $saved;
	}//end buildSoapEnvelope()

	/**
	 * Build a stuurgegevens XML element.
	 *
	 * @param array<string, string> $zender Sender info (organisatie, applicatie).
	 * @param array<string, string> $ontvanger Receiver info (organisatie, applicatie).
	 * @param string|null $referentienummer Reference number (auto-generated if null).
	 *
	 * @return string The stuurgegevens XML fragment.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function buildStuurgegevens(
		array $zender,
		array $ontvanger,
		?string $referentienummer = null,
	): string {
		$refNr = ($referentienummer ?? $this->generateUuid());

		$xml = '<stuf:stuurgegevens>';
		$xml .= '<stuf:berichtcode>Lk01</stuf:berichtcode>';
		$xml .= $this->renderParties(zender: $zender, ontvanger: $ontvanger);
		$xml .= '<stuf:referentienummer>' . htmlspecialchars($refNr) . '</stuf:referentienummer>';
		$xml .= '<stuf:tijdstipBericht>' . $this->timestamp() . '</stuf:tijdstipBericht>';
		$xml .= '</stuf:stuurgegevens>';

		return $xml;
	}//end buildStuurgegevens()

	/**
	 * Build a StUF Bv01 (bevestigingsbericht) response.
	 *
	 * @param array<string, string> $zender Sender info.
	 * @param array<string, string> $ontvanger Receiver info.
	 * @param string $crossRef Cross-reference to original message.
	 *
	 * @return string The complete SOAP Bv01 response.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function buildBv01(
		array $zender,
		array $ontvanger,
		string $crossRef,
	): string {
		$body = '<stuf:Bv01Bericht xmlns:stuf="' . StufMessageBuilder::NS_STUF . '">';
		$body .= '<stuf:stuurgegevens>';
		$body .= '<stuf:berichtcode>Bv01</stuf:berichtcode>';
		$body .= $this->renderParties(zender: $zender, ontvanger: $ontvanger);
		$body .= '<stuf:referentienummer>' . htmlspecialchars($this->generateUuid()) . '</stuf:referentienummer>';
		$body .= '<stuf:tijdstipBericht>' . $this->timestamp() . '</stuf:tijdstipBericht>';
		$body .= '<stuf:crossRefnummer>' . htmlspecialchars($crossRef) . '</stuf:crossRefnummer>';
		$body .= '</stuf:stuurgegevens>';
		$body .= '</stuf:Bv01Bericht>';

		return $this->buildSoapEnvelope(bodyXml: $body);
	}//end buildBv01()

	/**
	 * Build a StUF Fo01 (foutbericht) fault response.
	 *
	 * @param string $foutcode The fault code (e.g., StUF058).
	 * @param string $foutbeschrijving The fault description.
	 * @param string $plek Where the fault occurred (client/server).
	 * @param array<string, string> $zender Sender info.
	 * @param array<string, string> $ontvanger Receiver info.
	 *
	 * @return string The complete SOAP Fo01 response.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function buildFo01(
		string $foutcode,
		string $foutbeschrijving,
		string $plek,
		array $zender,
		array $ontvanger,
	): string {
		$body = '<stuf:Fo01Bericht xmlns:stuf="' . StufMessageBuilder::NS_STUF . '">';
		$body .= '<stuf:stuurgegevens>';
		$body .= '<stuf:berichtcode>Fo01</stuf:berichtcode>';
		$body .= $this->renderParties(zender: $zender, ontvanger: $ontvanger);
		$body .= '<stuf:referentienummer>' . htmlspecialchars($this->generateUuid()) . '</stuf:referentienummer>';
		$body .= '<stuf:tijdstipBericht>' . $this->timestamp() . '</stuf:tijdstipBericht>';
		$body .= '</stuf:stuurgegevens>';
		$body .= '<stuf:body>';
		$body .= '<stuf:code>' . htmlspecialchars($foutcode) . '</stuf:code>';
		$body .= '<stuf:plek>' . htmlspecialchars($plek) . '</stuf:plek>';
		$body .= '<stuf:omschrijving>' . htmlspecialchars($foutbeschrijving) . '</stuf:omschrijving>';
		$body .= '</stuf:body>';
		$body .= '</stuf:Fo01Bericht>';

		return $this->buildSoapEnvelope(bodyXml: $body);
	}//end buildFo01()

	/**
	 * Build a SOAP Fault response for invalid XML.
	 *
	 * @param string $faultString The fault description.
	 *
	 * @return string The SOAP Fault XML.
	 *
	 * @spec openspec/specs/stuf-integration/spec.md
	 */
	public function buildSoapFault(string $faultString): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$envelope = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Envelope');
		$dom->appendChild($envelope);

		$body = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Body');
		$envelope->appendChild($body);

		$fault = $dom->createElementNS(StufMessageBuilder::NS_SOAP, 'soap:Fault');
		$body->appendChild($fault);

		$faultcode = $dom->createElement('faultcode', 'Client');
		$fault->appendChild($faultcode);

		$faultstringEl = $dom->createElement('faultstring');
		$faultstringEl->appendChild($dom->createTextNode($faultString));
		$fault->appendChild($faultstringEl);

		$saved = $dom->saveXML();
		if ($saved === false) {
			return '';
		}

		return $saved;
	}//end buildSoapFault()

	/**
	 * Render the zender/ontvanger pair shared by every stuurgegevens header.
	 *
	 * @param array<string, string> $zender Sender info.
	 * @param array<string, string> $ontvanger Receiver info.
	 *
	 * @return string The XML fragment.
	 */
	private function renderParties(array $zender, array $ontvanger): string {
		$xml = '<stuf:zender>';
		$xml .= '<stuf:organisatie>' . htmlspecialchars($zender['organisation'] ?? '') . '</stuf:organisatie>';
		$xml .= '<stuf:applicatie>' . htmlspecialchars($zender['applicatie'] ?? '') . '</stuf:applicatie>';
		$xml .= '</stuf:zender>';
		$xml .= '<stuf:ontvanger>';
		$xml .= '<stuf:organisatie>' . htmlspecialchars($ontvanger['organisation'] ?? '') . '</stuf:organisatie>';
		$xml .= '<stuf:applicatie>' . htmlspecialchars($ontvanger['applicatie'] ?? '') . '</stuf:applicatie>';
		$xml .= '</stuf:ontvanger>';

		return $xml;
	}//end renderParties()

	/**
	 * The StUF `tijdstipBericht` value for right now.
	 *
	 * @return string The yyyyMMddHHmmss timestamp.
	 */
	private function timestamp(): string {
		return (new DateTimeImmutable())->format('YmdHis');
	}//end timestamp()

	/**
	 * Generate a UUID.
	 *
	 * @return string A UUID v4.
	 */
	private function generateUuid(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff)
		);
	}//end generateUuid()
}//end class
