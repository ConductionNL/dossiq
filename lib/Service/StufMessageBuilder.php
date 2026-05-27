<?php

/**
 * Procest StUF Message Builder
 *
 * Service for constructing StUF SOAP envelopes with proper namespace handling,
 * stuurgegevens population, and noValue attribute support.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-stuf-integration/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for constructing StUF SOAP XML messages.
 *
 * Handles SOAP envelope wrapping, StUF namespace management,
 * stuurgegevens population, and noValue attribute handling.
 *
 * @psalm-suppress UnusedClass
 */
class StufMessageBuilder
{
    /**
     * StUF base namespace.
     */
    public const NS_STUF = 'http://www.egem.nl/StUF/StUF0301';

    /**
     * StUF-ZKN namespace.
     */
    public const NS_ZKN = 'http://www.egem.nl/StUF/sector/zkn/0310';

    /**
     * StUF-BG namespace.
     */
    public const NS_BG = 'http://www.egem.nl/StUF/sector/bg/0310';

    /**
     * SOAP envelope namespace.
     */
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * XML Schema Instance namespace.
     */
    public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * NoValue attribute values.
     *
     * @var array<string, string>
     */
    public const NO_VALUE_TYPES = [
        'geenWaarde'          => 'geenWaarde',
        'waardeOnbekend'      => 'waardeOnbekend',
        'nietOndersteund'     => 'nietOndersteund',
        'vastgesteldOnbekend' => 'vastgesteldOnbekend',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a complete SOAP envelope wrapping a StUF message body.
     *
     * @param string $bodyXml The StUF message body XML (without SOAP wrapper).
     *
     * @return string The complete SOAP envelope XML.
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildSoapEnvelope(string $bodyXml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $envelope = $dom->createElementNS(self::NS_SOAP, 'soap:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:stuf', self::NS_STUF);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:zkn', self::NS_ZKN);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bg', self::NS_BG);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::NS_XSI);
        $dom->appendChild($envelope);

        $header = $dom->createElementNS(self::NS_SOAP, 'soap:Header');
        $envelope->appendChild($header);

        $body = $dom->createElementNS(self::NS_SOAP, 'soap:Body');
        $envelope->appendChild($body);

        // Import the body XML.
        $bodyDoc = new \DOMDocument();
        if ($bodyDoc->loadXML($bodyXml) === true) {
            $imported = $dom->importNode($bodyDoc->documentElement, true);
            $body->appendChild($imported);
        }

        $saved = $dom->saveXML();
        if ($saved === false) {
            return '';
        }

        return $saved;
    }//end buildSoapEnvelope()

    /**
     * Build stuurgegevens XML element.
     *
     * @param array<string, string> $zender           Sender info (organisatie, applicatie).
     * @param array<string, string> $ontvanger        Receiver info (organisatie, applicatie).
     * @param string|null           $referentienummer Reference number (auto-generated if null).
     *
     * @return string The stuurgegevens XML fragment.
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildStuurgegevens(
        array $zender,
        array $ontvanger,
        ?string $referentienummer=null,
    ): string {
        $refNr    = $referentienummer ?? $this->generateUuid();
        $tijdstip = (new \DateTimeImmutable())->format('YmdHis');

        $xml  = '<stuf:stuurgegevens>';
        $xml .= '<stuf:berichtcode>Lk01</stuf:berichtcode>';
        $xml .= '<stuf:zender>';
        $xml .= '<stuf:organisatie>'.htmlspecialchars($zender['organisatie'] ?? '').'</stuf:organisatie>';
        $xml .= '<stuf:applicatie>'.htmlspecialchars($zender['applicatie'] ?? '').'</stuf:applicatie>';
        $xml .= '</stuf:zender>';
        $xml .= '<stuf:ontvanger>';
        $xml .= '<stuf:organisatie>'.htmlspecialchars($ontvanger['organisatie'] ?? '').'</stuf:organisatie>';
        $xml .= '<stuf:applicatie>'.htmlspecialchars($ontvanger['applicatie'] ?? '').'</stuf:applicatie>';
        $xml .= '</stuf:ontvanger>';
        $xml .= '<stuf:referentienummer>'.htmlspecialchars($refNr).'</stuf:referentienummer>';
        $xml .= '<stuf:tijdstipBericht>'.$tijdstip.'</stuf:tijdstipBericht>';
        $xml .= '</stuf:stuurgegevens>';

        return $xml;
    }//end buildStuurgegevens()

    /**
     * Build a StUF Bv01 (bevestigingsbericht) response.
     *
     * @param array<string, string> $zender    Sender info.
     * @param array<string, string> $ontvanger Receiver info.
     * @param string                $crossRef  Cross-reference to original message.
     *
     * @return string The complete SOAP Bv01 response.
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildBv01(
        array $zender,
        array $ontvanger,
        string $crossRef,
    ): string {
        $tijdstip = (new \DateTimeImmutable())->format('YmdHis');

        $body  = '<stuf:Bv01Bericht xmlns:stuf="'.self::NS_STUF.'">';
        $body .= '<stuf:stuurgegevens>';
        $body .= '<stuf:berichtcode>Bv01</stuf:berichtcode>';
        $body .= '<stuf:zender>';
        $body .= '<stuf:organisatie>'.htmlspecialchars($zender['organisatie'] ?? '').'</stuf:organisatie>';
        $body .= '<stuf:applicatie>'.htmlspecialchars($zender['applicatie'] ?? '').'</stuf:applicatie>';
        $body .= '</stuf:zender>';
        $body .= '<stuf:ontvanger>';
        $body .= '<stuf:organisatie>'.htmlspecialchars($ontvanger['organisatie'] ?? '').'</stuf:organisatie>';
        $body .= '<stuf:applicatie>'.htmlspecialchars($ontvanger['applicatie'] ?? '').'</stuf:applicatie>';
        $body .= '</stuf:ontvanger>';
        $body .= '<stuf:referentienummer>'.htmlspecialchars($this->generateUuid()).'</stuf:referentienummer>';
        $body .= '<stuf:tijdstipBericht>'.$tijdstip.'</stuf:tijdstipBericht>';
        $body .= '<stuf:crossRefnummer>'.htmlspecialchars($crossRef).'</stuf:crossRefnummer>';
        $body .= '</stuf:stuurgegevens>';
        $body .= '</stuf:Bv01Bericht>';

        return $this->buildSoapEnvelope(bodyXml: $body);
    }//end buildBv01()

    /**
     * Build a StUF Fo01 (foutbericht) fault response.
     *
     * @param string                $foutcode         The fault code (e.g., StUF058).
     * @param string                $foutbeschrijving The fault description.
     * @param string                $plek             Where the fault occurred (client/server).
     * @param array<string, string> $zender           Sender info.
     * @param array<string, string> $ontvanger        Receiver info.
     *
     * @return string The complete SOAP Fo01 response.
     *
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildFo01(
        string $foutcode,
        string $foutbeschrijving,
        string $plek,
        array $zender,
        array $ontvanger,
    ): string {
        $tijdstip = (new \DateTimeImmutable())->format('YmdHis');

        $body  = '<stuf:Fo01Bericht xmlns:stuf="'.self::NS_STUF.'">';
        $body .= '<stuf:stuurgegevens>';
        $body .= '<stuf:berichtcode>Fo01</stuf:berichtcode>';
        $body .= '<stuf:zender>';
        $body .= '<stuf:organisatie>'.htmlspecialchars($zender['organisatie'] ?? '').'</stuf:organisatie>';
        $body .= '<stuf:applicatie>'.htmlspecialchars($zender['applicatie'] ?? '').'</stuf:applicatie>';
        $body .= '</stuf:zender>';
        $body .= '<stuf:ontvanger>';
        $body .= '<stuf:organisatie>'.htmlspecialchars($ontvanger['organisatie'] ?? '').'</stuf:organisatie>';
        $body .= '<stuf:applicatie>'.htmlspecialchars($ontvanger['applicatie'] ?? '').'</stuf:applicatie>';
        $body .= '</stuf:ontvanger>';
        $body .= '<stuf:referentienummer>'.htmlspecialchars($this->generateUuid()).'</stuf:referentienummer>';
        $body .= '<stuf:tijdstipBericht>'.$tijdstip.'</stuf:tijdstipBericht>';
        $body .= '</stuf:stuurgegevens>';
        $body .= '<stuf:body>';
        $body .= '<stuf:code>'.htmlspecialchars($foutcode).'</stuf:code>';
        $body .= '<stuf:plek>'.htmlspecialchars($plek).'</stuf:plek>';
        $body .= '<stuf:omschrijving>'.htmlspecialchars($foutbeschrijving).'</stuf:omschrijving>';
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
     * @psalm-suppress PossiblyUnusedMethod

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function buildSoapFault(string $faultString): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $envelope = $dom->createElementNS(self::NS_SOAP, 'soap:Envelope');
        $dom->appendChild($envelope);

        $body = $dom->createElementNS(self::NS_SOAP, 'soap:Body');
        $envelope->appendChild($body);

        $fault = $dom->createElementNS(self::NS_SOAP, 'soap:Fault');
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
     * Generate a UUID.
     *
     * @return string A UUID v4.
     */
    private function generateUuid(): string
    {
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
