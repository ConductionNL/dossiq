<?php

/**
 * StUF Message Parser
 *
 * Parses incoming StUF-ZKN and StUF-BG SOAP XML messages, extracting
 * structured data from StUF envelopes into PHP arrays.
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

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Parses StUF-ZKN and StUF-BG SOAP XML messages into PHP arrays.
 *
 * Handles XML namespace resolution, date format conversion from StUF to
 * ISO 8601, and extraction of stuurgegevens, case data, person data,
 * and document data from StUF message envelopes.
 */
class StufMessageParser
{

    /**
     * The StUF message builder for format conversions.
     *
     * @var StufMessageBuilder
     */
    private StufMessageBuilder $messageBuilder;


    /**
     * Constructor.
     *
     * @param StufMessageBuilder $messageBuilder The message builder for format conversions.
     */
    public function __construct(StufMessageBuilder $messageBuilder)
    {
        $this->messageBuilder = $messageBuilder;
    }//end __construct()


    /**
     * Parse a SOAP XML string and determine the message type.
     *
     * @param string $xml The raw SOAP XML content.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message with type and data.
     *
     * @throws RuntimeException When XML is invalid or message type is unknown.
     */
    public function parse(string $xml): array
    {
        $doc = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);

        if ($doc->loadXML($xml) === false) {
            libxml_use_internal_errors($previousErrors);
            throw new RuntimeException('Ongeldig XML bericht');
        }

        libxml_use_internal_errors($previousErrors);

        $xpath = new DOMXPath($doc);
        foreach (StufMessageBuilder::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        // Detect message type.
        if ($this->hasElement($xpath, '//zkn:zakLk01') === true) {
            return $this->parseZakLk01($xpath);
        }

        if ($this->hasElement($xpath, '//zkn:zakLv01') === true) {
            return $this->parseZakLv01($xpath);
        }

        if ($this->hasElement($xpath, '//zkn:edcLk01') === true) {
            return $this->parseEdcLk01($xpath);
        }

        if ($this->hasElement($xpath, '//bg:npsLv01') === true) {
            return $this->parseNpsLv01($xpath);
        }

        if ($this->hasElement($xpath, '//bg:npsLa01') === true) {
            return $this->parseNpsLa01($xpath);
        }

        if ($this->hasElement($xpath, '//stuf:Fo02Bericht') === true
            || $this->hasElement($xpath, '//stuf:Fo01Bericht') === true
        ) {
            return $this->parseFault($xpath);
        }

        throw new RuntimeException(
            'Onbekend StUF berichttype',
        );
    }//end parse()


    /**
     * Extract stuurgegevens from the message.
     *
     * @param DOMXPath $xpath     The XPath context.
     * @param string   $basePath  The base XPath path to the stuurgegevens parent.
     *
     * @return array<string, mixed> The stuurgegevens data.
     */
    public function extractStuurgegevens(DOMXPath $xpath, string $basePath = ''): array
    {
        $prefix = ($basePath !== '' ? $basePath . '/' : '//');

        return [
            'zender' => [
                'organisatie' => $this->getNodeValue(
                    $xpath,
                    $prefix . 'stuf:stuurgegevens/stuf:zender/stuf:organisatie',
                ),
                'applicatie'  => $this->getNodeValue(
                    $xpath,
                    $prefix . 'stuf:stuurgegevens/stuf:zender/stuf:applicatie',
                ),
            ],
            'ontvanger' => [
                'organisatie' => $this->getNodeValue(
                    $xpath,
                    $prefix . 'stuf:stuurgegevens/stuf:ontvanger/stuf:organisatie',
                ),
                'applicatie'  => $this->getNodeValue(
                    $xpath,
                    $prefix . 'stuf:stuurgegevens/stuf:ontvanger/stuf:applicatie',
                ),
            ],
            'referentienummer' => $this->getNodeValue(
                $xpath,
                $prefix . 'stuf:stuurgegevens/stuf:referentienummer',
            ),
            'tijdstipBericht'  => $this->getNodeValue(
                $xpath,
                $prefix . 'stuf:stuurgegevens/stuf:tijdstipBericht',
            ),
            'crossRefnummer'   => $this->getNodeValue(
                $xpath,
                $prefix . 'stuf:stuurgegevens/stuf:crossRefnummer',
            ),
        ];
    }//end extractStuurgegevens()


    /**
     * Parse a zakLk01 (case create/update) message.
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message.
     */
    private function parseZakLk01(DOMXPath $xpath): array
    {
        $stuurgegevens = $this->extractStuurgegevens($xpath, '//zkn:zakLk01');

        $mutatiesoort = $this->getAttributeValue(
            $xpath,
            '//zkn:zakLk01/zkn:object',
            'mutatiesoort',
        );

        $caseData = $this->extractCaseData($xpath, '//zkn:zakLk01/zkn:object');

        // Extract roles (initiator, behandelaar, etc.).
        $roles = $this->extractRoles($xpath, '//zkn:zakLk01/zkn:object');

        return [
            'type' => ($mutatiesoort === 'T' ? 'zakLk01-create' : 'zakLk01-update'),
            'data' => [
                'stuurgegevens' => $stuurgegevens,
                'mutatiesoort'  => $mutatiesoort,
                'case'          => $caseData,
                'roles'         => $roles,
            ],
        ];
    }//end parseZakLk01()


    /**
     * Parse a zakLv01 (case query) message.
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message.
     */
    private function parseZakLv01(DOMXPath $xpath): array
    {
        $stuurgegevens = $this->extractStuurgegevens($xpath, '//zkn:zakLv01');

        $queryData = [];

        $identifier = $this->getNodeValue(
            $xpath,
            '//zkn:zakLv01/zkn:gelijk/zkn:identificatie',
        );
        if ($identifier !== null) {
            $queryData['identifier'] = $identifier;
        }

        $startdatum = $this->getNodeValue(
            $xpath,
            '//zkn:zakLv01/zkn:van/zkn:startdatum',
        );
        if ($startdatum !== null) {
            $queryData['startDateFrom'] = $this->messageBuilder->dateFromStuf($startdatum);
        }

        $totEnMet = $this->getNodeValue(
            $xpath,
            '//zkn:zakLv01/zkn:totEnMet/zkn:startdatum',
        );
        if ($totEnMet !== null) {
            $queryData['startDateTo'] = $this->messageBuilder->dateFromStuf($totEnMet);
        }

        $maximumAantal = $this->getNodeValue(
            $xpath,
            '//zkn:zakLv01/stuf:maximumAantal',
        );
        if ($maximumAantal !== null) {
            $queryData['limit'] = (int) $maximumAantal;
        }

        return [
            'type' => 'zakLv01',
            'data' => [
                'stuurgegevens' => $stuurgegevens,
                'query'         => $queryData,
            ],
        ];
    }//end parseZakLv01()


    /**
     * Parse an edcLk01 (document create/update) message.
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message.
     */
    private function parseEdcLk01(DOMXPath $xpath): array
    {
        $stuurgegevens = $this->extractStuurgegevens($xpath, '//zkn:edcLk01');

        $mutatiesoort = $this->getAttributeValue(
            $xpath,
            '//zkn:edcLk01/zkn:object',
            'mutatiesoort',
        );

        $documentData = [
            'identificatie' => $this->getNodeValue(
                $xpath,
                '//zkn:edcLk01/zkn:object/zkn:identificatie',
            ),
            'titel'         => $this->getNodeValue(
                $xpath,
                '//zkn:edcLk01/zkn:object/zkn:dct.omschrijving',
            ),
            'formaat'       => $this->getNodeValue(
                $xpath,
                '//zkn:edcLk01/zkn:object/zkn:formaat',
            ),
            'inhoud'        => $this->getNodeValue(
                $xpath,
                '//zkn:edcLk01/zkn:object/zkn:inhoud',
            ),
        ];

        $zaakIdentifier = $this->getNodeValue(
            $xpath,
            '//zkn:edcLk01/zkn:object/zkn:isRelevantVoor/zkn:gerelateerde/zkn:identificatie',
        );

        return [
            'type' => ($mutatiesoort === 'T' ? 'edcLk01-create' : 'edcLk01-update'),
            'data' => [
                'stuurgegevens'  => $stuurgegevens,
                'mutatiesoort'   => $mutatiesoort,
                'document'       => $documentData,
                'zaakIdentifier' => $zaakIdentifier,
            ],
        ];
    }//end parseEdcLk01()


    /**
     * Parse an npsLv01 (person query) message.
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message.
     */
    private function parseNpsLv01(DOMXPath $xpath): array
    {
        $stuurgegevens = $this->extractStuurgegevens($xpath, '//bg:npsLv01');

        $queryData = [];

        $bsn = $this->getNodeValue(
            $xpath,
            '//bg:npsLv01/bg:gelijk/bg:inp.bsn',
        );
        if ($bsn !== null) {
            $queryData['bsn'] = $bsn;
        }

        $geslachtsnaam = $this->getNodeValue(
            $xpath,
            '//bg:npsLv01/bg:gelijk/bg:geslachtsnaam',
        );
        if ($geslachtsnaam !== null) {
            $queryData['geslachtsnaam'] = $geslachtsnaam;
        }

        $geboortedatum = $this->getNodeValue(
            $xpath,
            '//bg:npsLv01/bg:gelijk/bg:inp.geboortedatum',
        );
        if ($geboortedatum !== null) {
            $queryData['geboortedatum'] = $this->messageBuilder->dateFromStuf($geboortedatum);
        }

        return [
            'type' => 'npsLv01',
            'data' => [
                'stuurgegevens' => $stuurgegevens,
                'query'         => $queryData,
            ],
        ];
    }//end parseNpsLv01()


    /**
     * Parse an npsLa01 (person response) message.
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed message.
     */
    private function parseNpsLa01(DOMXPath $xpath): array
    {
        $stuurgegevens = $this->extractStuurgegevens($xpath, '//bg:npsLa01');

        $persons = [];
        $objectNodes = $xpath->query('//bg:npsLa01/bg:antwoord/bg:object');
        if ($objectNodes !== false) {
            foreach ($objectNodes as $objectNode) {
                $persons[] = $this->extractPersonData($xpath, $objectNode);
            }
        }

        return [
            'type' => 'npsLa01',
            'data' => [
                'stuurgegevens' => $stuurgegevens,
                'persons'       => $persons,
            ],
        ];
    }//end parseNpsLa01()


    /**
     * Parse a StUF fault message (Fo01/Fo02).
     *
     * @param DOMXPath $xpath The XPath context.
     *
     * @return array{type: string, data: array<string, mixed>} The parsed fault.
     */
    private function parseFault(DOMXPath $xpath): array
    {
        return [
            'type' => 'fault',
            'data' => [
                'code'    => $this->getNodeValue($xpath, '//stuf:code'),
                'message' => $this->getNodeValue($xpath, '//stuf:omschrijving'),
                'detail'  => $this->getNodeValue($xpath, '//stuf:details'),
            ],
        ];
    }//end parseFault()


    /**
     * Extract case data from a zaak object element.
     *
     * @param DOMXPath $xpath    The XPath context.
     * @param string   $basePath The base XPath to the object element.
     *
     * @return array<string, mixed> The extracted case data in Procest format.
     */
    private function extractCaseData(DOMXPath $xpath, string $basePath): array
    {
        $caseData = [];

        $fieldMap = [
            'identificatie'                  => 'identifier',
            'omschrijving'                   => 'title',
            'toelichting'                    => 'description',
            'startdatum'                     => 'startDate',
            'einddatum'                      => 'endDate',
            'einddatumGepland'               => 'plannedEndDate',
            'uiterlijkeEinddatumAfdoening'   => 'deadline',
        ];

        $dateFields = ['startdatum', 'einddatum', 'einddatumGepland', 'uiterlijkeEinddatumAfdoening'];

        foreach ($fieldMap as $stufField => $procestField) {
            $value = $this->getNodeValue($xpath, $basePath . '/zkn:' . $stufField);
            if ($value === null) {
                continue;
            }

            if (in_array($stufField, $dateFields, true) === true) {
                $value = $this->messageBuilder->dateFromStuf($value);
            }

            $caseData[$procestField] = $value;
        }

        // Case type resolution.
        $caseTypeCode = $this->getNodeValue(
            $xpath,
            $basePath . '/zkn:isVan/zkn:gerelateerde/zkn:code',
        );
        if ($caseTypeCode !== null) {
            $caseData['caseTypeCode'] = $caseTypeCode;
        }

        // Confidentiality mapping.
        $confidentiality = $this->getNodeValue(
            $xpath,
            $basePath . '/zkn:vertrouwelijkAanduiding',
        );
        if ($confidentiality !== null) {
            $caseData['confidentiality'] = $this->messageBuilder->confidentialityFromStuf(
                $confidentiality,
            );
        }

        return $caseData;
    }//end extractCaseData()


    /**
     * Extract roles from a zaak object element.
     *
     * @param DOMXPath $xpath    The XPath context.
     * @param string   $basePath The base XPath to the object element.
     *
     * @return array<int, array<string, mixed>> The extracted roles.
     */
    private function extractRoles(DOMXPath $xpath, string $basePath): array
    {
        $roles = [];

        $roleTypes = [
            'heeftAlsInitiator'      => 'initiator',
            'heeftAlsBehandelaar'    => 'behandelaar',
            'heeftAlsGemachtigde'    => 'gemachtigde',
            'heeftAlsBelanghebbende' => 'belanghebbende',
        ];

        foreach ($roleTypes as $stufElement => $genericRole) {
            $bsn = $this->getNodeValue(
                $xpath,
                $basePath . '/zkn:' . $stufElement . '/zkn:gerelateerde/bg:inp.bsn',
            );
            $vestigingsnummer = $this->getNodeValue(
                $xpath,
                $basePath . '/zkn:' . $stufElement . '/zkn:gerelateerde/bg:vestigingsNummer',
            );

            if ($bsn !== null) {
                $roles[] = [
                    'genericRole' => $genericRole,
                    'type'        => 'natuurlijk_persoon',
                    'bsn'         => $bsn,
                ];
            }

            if ($vestigingsnummer !== null) {
                $roles[] = [
                    'genericRole'      => $genericRole,
                    'type'             => 'vestiging',
                    'vestigingsnummer' => $vestigingsnummer,
                ];
            }
        }

        return $roles;
    }//end extractRoles()


    /**
     * Extract person data from an npsLa01 object node.
     *
     * @param DOMXPath    $xpath      The XPath context.
     * @param \DOMElement $objectNode The person object DOM element.
     *
     * @return array<string, mixed> The extracted person data.
     */
    private function extractPersonData(DOMXPath $xpath, \DOMElement $objectNode): array
    {
        $nodePath = $objectNode->getNodePath();

        $personData = [];

        $fields = [
            'inp.bsn'                     => 'bsn',
            'geslachtsnaam'                => 'lastName',
            'voorvoegselGeslachtsnaam'     => 'namePrefix',
            'voornamen'                    => 'firstName',
        ];

        foreach ($fields as $bgField => $procestField) {
            $value = $this->getNodeValue($xpath, $nodePath . '/bg:' . $bgField);
            if ($value !== null) {
                $personData[$procestField] = $value;
            }
        }

        $geboortedatum = $this->getNodeValue($xpath, $nodePath . '/bg:inp.geboortedatum');
        if ($geboortedatum !== null) {
            $personData['dateOfBirth'] = $this->messageBuilder->dateFromStuf($geboortedatum);
        }

        return $personData;
    }//end extractPersonData()


    /**
     * Get the text value of the first node matching an XPath expression.
     *
     * @param DOMXPath $xpath      The XPath context.
     * @param string   $expression The XPath expression.
     *
     * @return string|null The node value, or null if not found.
     */
    private function getNodeValue(DOMXPath $xpath, string $expression): ?string
    {
        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = $nodes->item(0)->textContent;
        return ($value !== '' ? $value : null);
    }//end getNodeValue()


    /**
     * Get an attribute value from the first node matching an XPath expression.
     *
     * @param DOMXPath $xpath         The XPath context.
     * @param string   $expression    The XPath expression.
     * @param string   $attributeName The attribute name.
     *
     * @return string|null The attribute value, or null if not found.
     */
    private function getAttributeValue(
        DOMXPath $xpath,
        string $expression,
        string $attributeName,
    ): ?string {
        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if ($node instanceof \DOMElement === false) {
            return null;
        }

        if ($node->hasAttribute($attributeName) === false) {
            return null;
        }

        return $node->getAttribute($attributeName);
    }//end getAttributeValue()


    /**
     * Check if an XPath expression matches any nodes.
     *
     * @param DOMXPath $xpath      The XPath context.
     * @param string   $expression The XPath expression.
     *
     * @return bool True if at least one node matches.
     */
    private function hasElement(DOMXPath $xpath, string $expression): bool
    {
        $nodes = $xpath->query($expression);
        return ($nodes !== false && $nodes->length > 0);
    }//end hasElement()


}//end class
