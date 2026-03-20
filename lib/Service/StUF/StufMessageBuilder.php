<?php

/**
 * StUF Message Builder
 *
 * Constructs StUF-ZKN and StUF-BG SOAP XML messages for outbound
 * communication with legacy Dutch government systems.
 *
 * @category Service
 * @package  OCA\Procest\Service\StUF
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\StUF;

use DateTime;
use DOMDocument;
use DOMElement;

/**
 * Builds StUF-ZKN and StUF-BG SOAP XML messages.
 *
 * Handles XML namespace management, date format conversion, stuurgegevens
 * population, and noValue attribute handling per the StUF 3.01 standard.
 */
class StufMessageBuilder
{

    /**
     * StUF XML namespaces.
     *
     * @var array<string, string>
     */
    public const NAMESPACES = [
        'soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'stuf'    => 'http://www.egem.nl/StUF/StUF0301',
        'zkn'     => 'http://www.egem.nl/StUF/sector/zkn/0310',
        'bg'      => 'http://www.egem.nl/StUF/sector/bg/0310',
        'xsi'     => 'http://www.w3.org/2001/XMLSchema-instance',
        'gml'     => 'http://www.opengis.net/gml',
    ];

    /**
     * Confidentiality enum mapping from Procest values to StUF values.
     *
     * @var array<string, string>
     */
    public const CONFIDENTIALITY_TO_STUF = [
        'public'              => 'OPENBAAR',
        'restricted'          => 'BEPERKT OPENBAAR',
        'internal'            => 'INTERN',
        'case_sensitive'      => 'ZAAKVERTROUWELIJK',
        'confidential'        => 'VERTROUWELIJK',
        'highly_confidential' => 'CONFIDENTIEEL',
        'secret'              => 'GEHEIM',
        'top_secret'          => 'ZEER GEHEIM',
    ];

    /**
     * Confidentiality enum mapping from StUF values to Procest values.
     *
     * @var array<string, string>
     */
    public const CONFIDENTIALITY_FROM_STUF = [
        'OPENBAAR'          => 'public',
        'BEPERKT OPENBAAR'  => 'restricted',
        'INTERN'            => 'internal',
        'ZAAKVERTROUWELIJK' => 'case_sensitive',
        'VERTROUWELIJK'     => 'confidential',
        'CONFIDENTIEEL'     => 'highly_confidential',
        'GEHEIM'            => 'secret',
        'ZEER GEHEIM'       => 'top_secret',
    ];


    /**
     * Build a StUF-ZKN zakLk01 SOAP envelope for case mutations.
     *
     * @param array<string, mixed> $caseData       The case data in Procest format.
     * @param string               $mutatiesoort   The mutation type: T (create), W (update).
     * @param array<string, mixed> $stuurgegevens  The stuurgegevens configuration.
     *
     * @return string The complete SOAP XML envelope.
     */
    public function buildZakLk01(
        array $caseData,
        string $mutatiesoort,
        array $stuurgegevens,
    ): string {
        $doc = $this->createSoapDocument();
        $body = $this->getSoapBody($doc);

        $zakLk01 = $doc->createElementNS(self::NAMESPACES['zkn'], 'zkn:zakLk01');
        $body->appendChild($zakLk01);

        $this->addStuurgegevens($doc, $zakLk01, $stuurgegevens);

        $object = $doc->createElementNS(self::NAMESPACES['zkn'], 'zkn:object');
        $object->setAttributeNS(
            self::NAMESPACES['stuf'],
            'stuf:entiteittype',
            'ZAK',
        );
        $object->setAttributeNS(
            self::NAMESPACES['stuf'],
            'stuf:sleutelVerzwordendeGegevens',
            'identificatie',
        );
        $zakLk01->appendChild($object);

        $this->addCaseDataToElement($doc, $object, $caseData, $mutatiesoort);

        return $doc->saveXML();
    }//end buildZakLk01()


    /**
     * Build a StUF-BG npsLv01 SOAP envelope for person lookup.
     *
     * @param array<string, mixed> $queryParams   The query parameters (e.g., bsn, geslachtsnaam).
     * @param array<string, mixed> $stuurgegevens The stuurgegevens configuration.
     *
     * @return string The complete SOAP XML envelope.
     */
    public function buildNpsLv01(
        array $queryParams,
        array $stuurgegevens,
    ): string {
        $doc = $this->createSoapDocument();
        $body = $this->getSoapBody($doc);

        $npsLv01 = $doc->createElementNS(self::NAMESPACES['bg'], 'bg:npsLv01');
        $body->appendChild($npsLv01);

        $this->addStuurgegevens($doc, $npsLv01, $stuurgegevens);

        $gelijk = $doc->createElementNS(self::NAMESPACES['bg'], 'bg:gelijk');
        $gelijk->setAttributeNS(
            self::NAMESPACES['stuf'],
            'stuf:entiteittype',
            'NPS',
        );
        $npsLv01->appendChild($gelijk);

        if (isset($queryParams['bsn']) === true) {
            $bsnEl = $doc->createElementNS(
                self::NAMESPACES['bg'],
                'bg:inp.bsn',
                htmlspecialchars((string) $queryParams['bsn'], ENT_XML1),
            );
            $gelijk->appendChild($bsnEl);
        }

        if (isset($queryParams['geslachtsnaam']) === true) {
            $naamEl = $doc->createElementNS(
                self::NAMESPACES['bg'],
                'bg:geslachtsnaam',
                htmlspecialchars((string) $queryParams['geslachtsnaam'], ENT_XML1),
            );
            $gelijk->appendChild($naamEl);
        }

        if (isset($queryParams['geboortedatum']) === true) {
            $datumEl = $doc->createElementNS(
                self::NAMESPACES['bg'],
                'bg:inp.geboortedatum',
                $this->dateToStuf((string) $queryParams['geboortedatum']),
            );
            $gelijk->appendChild($datumEl);
        }

        return $doc->saveXML();
    }//end buildNpsLv01()


    /**
     * Build a StUF-ZKN edcLk01 SOAP envelope for document notifications.
     *
     * @param array<string, mixed> $documentData   The document metadata.
     * @param string               $mutatiesoort   The mutation type: T (create), W (update).
     * @param string               $zaakIdentifier The related case identifier.
     * @param array<string, mixed> $stuurgegevens  The stuurgegevens configuration.
     *
     * @return string The complete SOAP XML envelope.
     */
    public function buildEdcLk01(
        array $documentData,
        string $mutatiesoort,
        string $zaakIdentifier,
        array $stuurgegevens,
    ): string {
        $doc = $this->createSoapDocument();
        $body = $this->getSoapBody($doc);

        $edcLk01 = $doc->createElementNS(self::NAMESPACES['zkn'], 'zkn:edcLk01');
        $body->appendChild($edcLk01);

        $this->addStuurgegevens($doc, $edcLk01, $stuurgegevens);

        $object = $doc->createElementNS(self::NAMESPACES['zkn'], 'zkn:object');
        $object->setAttributeNS(
            self::NAMESPACES['stuf'],
            'stuf:entiteittype',
            'EDC',
        );
        $edcLk01->appendChild($object);

        if (isset($documentData['identificatie']) === true) {
            $idEl = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:identificatie',
                htmlspecialchars((string) $documentData['identificatie'], ENT_XML1),
            );
            $object->appendChild($idEl);
        }

        if (isset($documentData['titel']) === true) {
            $titleEl = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:dct.omschrijving',
                htmlspecialchars((string) $documentData['titel'], ENT_XML1),
            );
            $object->appendChild($titleEl);
        }

        if (isset($documentData['formaat']) === true) {
            $formatEl = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:formaat',
                htmlspecialchars((string) $documentData['formaat'], ENT_XML1),
            );
            $object->appendChild($formatEl);
        }

        if (isset($documentData['inhoud']) === true) {
            $contentEl = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:inhoud',
                (string) $documentData['inhoud'],
            );
            $object->appendChild($contentEl);
        }

        // Add zaak reference.
        $isRelevantVoor = $doc->createElementNS(
            self::NAMESPACES['zkn'],
            'zkn:isRelevantVoor',
        );
        $gerelateerde = $doc->createElementNS(
            self::NAMESPACES['zkn'],
            'zkn:gerelateerde',
        );
        $zaakIdEl = $doc->createElementNS(
            self::NAMESPACES['zkn'],
            'zkn:identificatie',
            htmlspecialchars($zaakIdentifier, ENT_XML1),
        );
        $gerelateerde->appendChild($zaakIdEl);
        $isRelevantVoor->appendChild($gerelateerde);
        $object->appendChild($isRelevantVoor);

        return $doc->saveXML();
    }//end buildEdcLk01()


    /**
     * Build a StUF Bv01 confirmation response.
     *
     * @param array<string, mixed> $stuurgegevens  The stuurgegevens for the response.
     * @param string               $crossRefnummer The reference number from the original request.
     *
     * @return string The SOAP XML Bv01 response.
     */
    public function buildBv01(
        array $stuurgegevens,
        string $crossRefnummer,
    ): string {
        $doc = $this->createSoapDocument();
        $body = $this->getSoapBody($doc);

        $bv01 = $doc->createElementNS(self::NAMESPACES['stuf'], 'stuf:Bv01Bericht');
        $body->appendChild($bv01);

        $responseStuurgegevens = $stuurgegevens;
        $responseStuurgegevens['crossRefnummer'] = $crossRefnummer;
        // Swap zender and ontvanger for the response.
        $tmpZender = ($responseStuurgegevens['zender'] ?? []);
        $responseStuurgegevens['zender'] = ($responseStuurgegevens['ontvanger'] ?? []);
        $responseStuurgegevens['ontvanger'] = $tmpZender;

        $this->addStuurgegevens($doc, $bv01, $responseStuurgegevens);

        return $doc->saveXML();
    }//end buildBv01()


    /**
     * Build a StUF Fo01 fault response.
     *
     * @param string               $foutcode       The StUF fault code.
     * @param string               $foutbeschrijving The fault description.
     * @param string               $plek           Where the fault occurred (client/server).
     * @param array<string, mixed> $stuurgegevens  The stuurgegevens for the response.
     * @param string               $crossRefnummer The reference number from the original request.
     *
     * @return string The SOAP XML fault response.
     */
    public function buildFo01(
        string $foutcode,
        string $foutbeschrijving,
        string $plek,
        array $stuurgegevens,
        string $crossRefnummer = '',
    ): string {
        $doc = $this->createSoapDocument();
        $body = $this->getSoapBody($doc);

        $fo01 = $doc->createElementNS(self::NAMESPACES['stuf'], 'stuf:Fo01Bericht');
        $body->appendChild($fo01);

        $responseStuurgegevens = $stuurgegevens;
        if ($crossRefnummer !== '') {
            $responseStuurgegevens['crossRefnummer'] = $crossRefnummer;
        }

        $tmpZender = ($responseStuurgegevens['zender'] ?? []);
        $responseStuurgegevens['zender'] = ($responseStuurgegevens['ontvanger'] ?? []);
        $responseStuurgegevens['ontvanger'] = $tmpZender;

        $this->addStuurgegevens($doc, $fo01, $responseStuurgegevens);

        $body = $doc->createElementNS(self::NAMESPACES['stuf'], 'stuf:body');
        $fo01->appendChild($body);

        $codeEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:code',
            htmlspecialchars($foutcode, ENT_XML1),
        );
        $body->appendChild($codeEl);

        $beschrijvingEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:omschrijving',
            htmlspecialchars($foutbeschrijving, ENT_XML1),
        );
        $body->appendChild($beschrijvingEl);

        $plekEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:plek',
            htmlspecialchars($plek, ENT_XML1),
        );
        $body->appendChild($plekEl);

        return $doc->saveXML();
    }//end buildFo01()


    /**
     * Convert an ISO 8601 date to StUF format (YYYYMMDD).
     *
     * @param string $isoDate The ISO 8601 date string.
     *
     * @return string The StUF-formatted date string.
     */
    public function dateToStuf(string $isoDate): string
    {
        $date = new DateTime($isoDate);
        return $date->format('Ymd');
    }//end dateToStuf()


    /**
     * Convert an ISO 8601 datetime to StUF format (YYYYMMDDHHmmss).
     *
     * @param string $isoDatetime The ISO 8601 datetime string.
     *
     * @return string The StUF-formatted datetime string.
     */
    public function datetimeToStuf(string $isoDatetime): string
    {
        $date = new DateTime($isoDatetime);
        return $date->format('YmdHis');
    }//end datetimeToStuf()


    /**
     * Convert a StUF date (YYYYMMDD) to ISO 8601 format.
     *
     * @param string $stufDate The StUF-formatted date string.
     *
     * @return string The ISO 8601 date string.
     */
    public function dateFromStuf(string $stufDate): string
    {
        $date = DateTime::createFromFormat('Ymd', $stufDate);
        if ($date === false) {
            return $stufDate;
        }

        return $date->format('Y-m-d');
    }//end dateFromStuf()


    /**
     * Convert a StUF datetime (YYYYMMDDHHmmss) to ISO 8601 format.
     *
     * @param string $stufDatetime The StUF-formatted datetime string.
     *
     * @return string The ISO 8601 datetime string.
     */
    public function datetimeFromStuf(string $stufDatetime): string
    {
        $date = DateTime::createFromFormat('YmdHis', $stufDatetime);
        if ($date === false) {
            return $stufDatetime;
        }

        return $date->format('Y-m-d\TH:i:sP');
    }//end datetimeFromStuf()


    /**
     * Map a confidentiality value from Procest to StUF format.
     *
     * @param string $procestValue The Procest confidentiality value.
     *
     * @return string The StUF confidentiality value.
     */
    public function confidentialityToStuf(string $procestValue): string
    {
        return (self::CONFIDENTIALITY_TO_STUF[$procestValue] ?? 'OPENBAAR');
    }//end confidentialityToStuf()


    /**
     * Map a confidentiality value from StUF to Procest format.
     *
     * @param string $stufValue The StUF confidentiality value.
     *
     * @return string The Procest confidentiality value.
     */
    public function confidentialityFromStuf(string $stufValue): string
    {
        return (self::CONFIDENTIALITY_FROM_STUF[$stufValue] ?? 'public');
    }//end confidentialityFromStuf()


    /**
     * Create a new SOAP XML document with standard namespaces.
     *
     * @return DOMDocument The initialized DOM document.
     */
    private function createSoapDocument(): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $envelope = $doc->createElementNS(
            self::NAMESPACES['soapenv'],
            'soapenv:Envelope',
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:stuf',
            self::NAMESPACES['stuf'],
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:zkn',
            self::NAMESPACES['zkn'],
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:bg',
            self::NAMESPACES['bg'],
        );
        $envelope->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::NAMESPACES['xsi'],
        );
        $doc->appendChild($envelope);

        $header = $doc->createElementNS(
            self::NAMESPACES['soapenv'],
            'soapenv:Header',
        );
        $envelope->appendChild($header);

        $body = $doc->createElementNS(
            self::NAMESPACES['soapenv'],
            'soapenv:Body',
        );
        $envelope->appendChild($body);

        return $doc;
    }//end createSoapDocument()


    /**
     * Get the SOAP Body element from a document.
     *
     * @param DOMDocument $doc The SOAP document.
     *
     * @return DOMElement The Body element.
     */
    private function getSoapBody(DOMDocument $doc): DOMElement
    {
        $bodies = $doc->getElementsByTagNameNS(
            self::NAMESPACES['soapenv'],
            'Body',
        );
        return $bodies->item(0);
    }//end getSoapBody()


    /**
     * Add stuurgegevens element to a StUF message.
     *
     * @param DOMDocument          $doc           The DOM document.
     * @param DOMElement           $parent        The parent element.
     * @param array<string, mixed> $stuurgegevens The stuurgegevens data.
     *
     * @return void
     */
    private function addStuurgegevens(
        DOMDocument $doc,
        DOMElement $parent,
        array $stuurgegevens,
    ): void {
        $stuurEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:stuurgegevens',
        );
        $parent->appendChild($stuurEl);

        // Zender.
        $zenderEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:zender',
        );
        $stuurEl->appendChild($zenderEl);

        $zender = ($stuurgegevens['zender'] ?? []);
        if (isset($zender['organisatie']) === true) {
            $orgEl = $doc->createElementNS(
                self::NAMESPACES['stuf'],
                'stuf:organisatie',
                htmlspecialchars((string) $zender['organisatie'], ENT_XML1),
            );
            $zenderEl->appendChild($orgEl);
        }

        if (isset($zender['applicatie']) === true) {
            $appEl = $doc->createElementNS(
                self::NAMESPACES['stuf'],
                'stuf:applicatie',
                htmlspecialchars((string) $zender['applicatie'], ENT_XML1),
            );
            $zenderEl->appendChild($appEl);
        }

        // Ontvanger.
        $ontvangerEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:ontvanger',
        );
        $stuurEl->appendChild($ontvangerEl);

        $ontvanger = ($stuurgegevens['ontvanger'] ?? []);
        if (isset($ontvanger['organisatie']) === true) {
            $orgEl = $doc->createElementNS(
                self::NAMESPACES['stuf'],
                'stuf:organisatie',
                htmlspecialchars((string) $ontvanger['organisatie'], ENT_XML1),
            );
            $ontvangerEl->appendChild($orgEl);
        }

        if (isset($ontvanger['applicatie']) === true) {
            $appEl = $doc->createElementNS(
                self::NAMESPACES['stuf'],
                'stuf:applicatie',
                htmlspecialchars((string) $ontvanger['applicatie'], ENT_XML1),
            );
            $ontvangerEl->appendChild($appEl);
        }

        // Referentienummer.
        $refnummer = ($stuurgegevens['referentienummer'] ?? $this->generateReferentienummer());
        $refEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:referentienummer',
            $refnummer,
        );
        $stuurEl->appendChild($refEl);

        // Tijdstip bericht.
        $tijdstip = ($stuurgegevens['tijdstipBericht'] ?? (new DateTime())->format('YmdHis'));
        $tijdEl = $doc->createElementNS(
            self::NAMESPACES['stuf'],
            'stuf:tijdstipBericht',
            $tijdstip,
        );
        $stuurEl->appendChild($tijdEl);

        // Cross-reference number (for responses).
        if (isset($stuurgegevens['crossRefnummer']) === true) {
            $crossRefEl = $doc->createElementNS(
                self::NAMESPACES['stuf'],
                'stuf:crossRefnummer',
                htmlspecialchars($stuurgegevens['crossRefnummer'], ENT_XML1),
            );
            $stuurEl->appendChild($crossRefEl);
        }
    }//end addStuurgegevens()


    /**
     * Add case data fields to a StUF XML element.
     *
     * @param DOMDocument          $doc           The DOM document.
     * @param DOMElement           $parent        The parent element.
     * @param array<string, mixed> $caseData      The case data.
     * @param string               $mutatiesoort  The mutation type.
     *
     * @return void
     */
    private function addCaseDataToElement(
        DOMDocument $doc,
        DOMElement $parent,
        array $caseData,
        string $mutatiesoort,
    ): void {
        $parent->setAttribute('mutatiesoort', $mutatiesoort);

        $fieldMap = [
            'identifier'      => 'identificatie',
            'title'           => 'omschrijving',
            'description'     => 'toelichting',
            'startDate'       => 'startdatum',
            'endDate'         => 'einddatum',
            'plannedEndDate'  => 'einddatumGepland',
            'deadline'        => 'uiterlijkeEinddatumAfdoening',
        ];

        $dateFields = ['startDate', 'endDate', 'plannedEndDate', 'deadline'];

        foreach ($fieldMap as $procestField => $stufField) {
            if (isset($caseData[$procestField]) === false) {
                continue;
            }

            $value = (string) $caseData[$procestField];
            if (in_array($procestField, $dateFields, true) === true) {
                $value = $this->dateToStuf($value);
            } else {
                $value = htmlspecialchars($value, ENT_XML1);
            }

            $el = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:' . $stufField,
                $value,
            );
            $parent->appendChild($el);
        }

        // Confidentiality mapping.
        if (isset($caseData['confidentiality']) === true) {
            $stufConfidentiality = $this->confidentialityToStuf(
                (string) $caseData['confidentiality'],
            );
            $confEl = $doc->createElementNS(
                self::NAMESPACES['zkn'],
                'zkn:vertrouwelijkAanduiding',
                $stufConfidentiality,
            );
            $parent->appendChild($confEl);
        }
    }//end addCaseDataToElement()


    /**
     * Generate a UUID-based referentienummer.
     *
     * @return string A UUID v4 string.
     */
    private function generateReferentienummer(): string
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
            mt_rand(0, 0xffff),
        );
    }//end generateReferentienummer()


}//end class
