<?php

/**
 * Procest StUF Controller
 *
 * Handles inbound StUF SOAP messages via raw XML POST. Parses SOAP envelopes,
 * dispatches to appropriate handlers based on message type, and returns
 * SOAP XML responses.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-stuf-integration/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-stuf-integration/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-stuf-integration/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\StufFieldMappingService;
use OCA\Procest\Service\StufMessageBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for inbound StUF SOAP messages.
 *
 * Accepts raw XML POST at /api/stuf/{service}, parses SOAP envelopes,
 * and dispatches to handlers based on the StUF message type (zakLk01,
 * zakLv01, npsLv01, etc.).
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StufController extends Controller
{
    /**
     * Default stuurgegevens for this Procest instance (zender).
     *
     * @var array<string, string>
     */
    private const DEFAULT_ZENDER = [
        'organisatie' => 'Procest',
        'applicatie'  => 'Procest',
    ];

    /**
     * Constructor.
     *
     * @param string                  $appName        The app name.
     * @param IRequest                $request        The request object.
     * @param StufFieldMappingService $mappingService The field mapping service.
     * @param StufMessageBuilder      $messageBuilder The message builder service.
     * @param LoggerInterface         $logger         The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly StufFieldMappingService $mappingService,
        private readonly StufMessageBuilder $messageBuilder,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Handle inbound StUF-ZKN SOAP messages for case operations.
     *
     * @return DataDisplayResponse SOAP XML response.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function zaken(): DataDisplayResponse
    {
        return $this->handleSoapMessage(service: 'zaken');
    }//end zaken()

    /**
     * Handle inbound StUF-BG SOAP messages for person operations.
     *
     * @return DataDisplayResponse SOAP XML response.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function personen(): DataDisplayResponse
    {
        return $this->handleSoapMessage(service: 'personen');
    }//end personen()

    /**
     * Handle an inbound SOAP message.
     *
     * @param string $service The service type ('zaken' or 'personen').
     *
     * @return DataDisplayResponse The SOAP XML response.
     */
    private function handleSoapMessage(string $service): DataDisplayResponse
    {
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || $rawBody === '') {
            $response = $this->messageBuilder->buildSoapFault('Leeg bericht ontvangen');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
        }

        // Parse the XML.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $parseResult = $dom->loadXML($rawBody);
        $errors      = libxml_get_errors();
        libxml_clear_errors();

        if ($parseResult === false || empty($errors) === false) {
            $this->logger->warning('Invalid XML received at StUF endpoint: '.$service);
            $response = $this->messageBuilder->buildSoapFault('Ongeldig XML bericht');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
        }

        // Extract the SOAP Body content.
        $bodyElements = $dom->getElementsByTagNameNS(
            StufMessageBuilder::NS_SOAP,
            'Body'
        );

        if ($bodyElements->length === 0) {
            $response = $this->messageBuilder->buildSoapFault('Geen SOAP Body gevonden');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
        }

        $body = $bodyElements->item(0);
        if ($body === null || $body->hasChildNodes() === false) {
            $response = $this->messageBuilder->buildSoapFault('Lege SOAP Body');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
        }

        // Get the first child element (the StUF message).
        $messageElement = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $messageElement = $child;
                break;
            }
        }

        if ($messageElement === null) {
            $response = $this->messageBuilder->buildSoapFault('Geen StUF bericht element gevonden');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
        }

        // Dispatch based on message type.
        $messageType = $messageElement->localName;

        $this->logger->info(
            'Received StUF message: {type} at {service}',
            ['type' => $messageType, 'service' => $service]
        );

        return match ($messageType) {
            'zakLk01' => $this->handleZakLk01(message: $messageElement),
            'zakLv01' => $this->handleZakLv01(message: $messageElement),
            'npsLv01' => $this->handleNpsLv01(message: $messageElement),
            'edcLk01' => $this->handleEdcLk01(message: $messageElement),
            default => $this->handleUnknownMessage(messageType: $messageType),
        };
    }//end handleSoapMessage()

    /**
     * Handle zakLk01 (case create/update) message.
     *
     * @param \DOMElement $message The StUF message element.
     *
     * @return DataDisplayResponse
     */
    private function handleZakLk01(\DOMElement $message): DataDisplayResponse
    {
        // Extract mutatiesoort.
        $objectElements = $message->getElementsByTagName('object');
        if ($objectElements->length === 0) {
            $response = $this->messageBuilder->buildFo01(
                'StUF055',
                'Geen object element in zakLk01',
                'server',
                self::DEFAULT_ZENDER,
                []
            );
            return $this->soapResponse(xml: $response);
        }

        $objectEl     = $objectElements->item(0);
        $mutatiesoort = $message->getAttribute('mutatiesoort');

        // Extract basic fields.
        $stufFields = $this->extractFields(
                element: $objectEl,
                fieldNames: [
                    'identificatie',
                    'omschrijving',
                    'toelichting',
                    'startdatum',
                    'einddatum',
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
                'id'           => $internalData['identifier'] ?? 'none',
            ]
        );

        // Extract referentienummer for cross-reference.
        $stuurgegevens = $message->getElementsByTagName('stuurgegevens');
        $crossRef      = '';
        if ($stuurgegevens->length > 0) {
            $refElements = $stuurgegevens->item(0)->getElementsByTagName('referentienummer');
            if ($refElements->length > 0) {
                $crossRef = $refElements->item(0)->textContent ?? '';
            }
        }

        // In a full implementation, create/update OpenRegister objects here.
        // For now, return a Bv01 confirmation.
        $response = $this->messageBuilder->buildBv01(
            self::DEFAULT_ZENDER,
            [],
            $crossRef
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
    private function handleZakLv01(\DOMElement $message): DataDisplayResponse
    {
        // Extract query criteria from gelijk element.
        $gelijkElements = $message->getElementsByTagName('gelijk');
        $criteria       = [];

        if ($gelijkElements->length > 0) {
            $gelijk   = $gelijkElements->item(0);
            $criteria = $this->extractFields(
                    element: $gelijk,
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
        $body  = '<zkn:zakLa01 xmlns:zkn="'.StufMessageBuilder::NS_ZKN.'" '
            .'xmlns:stuf="'.StufMessageBuilder::NS_STUF.'">';
        $body .= $this->messageBuilder->buildStuurgegevens(self::DEFAULT_ZENDER, []);
        $body .= '<zkn:antwoord/>';
        $body .= '</zkn:zakLa01>';

        $response = $this->messageBuilder->buildSoapEnvelope($body);

        return $this->soapResponse(xml: $response);
    }//end handleZakLv01()

    /**
     * Handle npsLv01 (person query) message.
     *
     * @param \DOMElement $message The StUF message element.
     *
     * @return DataDisplayResponse
     */
    private function handleNpsLv01(\DOMElement $message): DataDisplayResponse
    {
        // Extract BSN from gelijk element.
        $gelijkElements = $message->getElementsByTagName('gelijk');
        $bsn            = '';

        if ($gelijkElements->length > 0) {
            $bsnElements = $gelijkElements->item(0)->getElementsByTagName('bsn');
            if ($bsnElements->length > 0) {
                $bsn = $bsnElements->item(0)->textContent ?? '';
            }
        }

        $this->logger->info(
            'Processed npsLv01 person query for BSN {bsn}',
            ['bsn' => substr($bsn, 0, 3).'***']
        );

        // In a full implementation, query OpenRegister for person data.
        // For now, return an empty npsLa01 response.
        $body  = '<bg:npsLa01 xmlns:bg="'.StufMessageBuilder::NS_BG.'" '
            .'xmlns:stuf="'.StufMessageBuilder::NS_STUF.'">';
        $body .= $this->messageBuilder->buildStuurgegevens(self::DEFAULT_ZENDER, []);
        $body .= '<bg:antwoord/>';
        $body .= '</bg:npsLa01>';

        $response = $this->messageBuilder->buildSoapEnvelope($body);

        return $this->soapResponse(xml: $response);
    }//end handleNpsLv01()

    /**
     * Handle edcLk01 (document create/update) message.
     *
     * @param \DOMElement $message The StUF message element.
     *
     * @return DataDisplayResponse
     */
    private function handleEdcLk01(\DOMElement $message): DataDisplayResponse
    {
        $this->logger->info('Processed edcLk01 document message');

        // Extract referentienummer.
        $stuurgegevens = $message->getElementsByTagName('stuurgegevens');
        $crossRef      = '';
        if ($stuurgegevens->length > 0) {
            $refElements = $stuurgegevens->item(0)->getElementsByTagName('referentienummer');
            if ($refElements->length > 0) {
                $crossRef = $refElements->item(0)->textContent ?? '';
            }
        }

        $response = $this->messageBuilder->buildBv01(
            self::DEFAULT_ZENDER,
            [],
            $crossRef
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
    private function handleUnknownMessage(string $messageType): DataDisplayResponse
    {
        $this->logger->warning('Unknown StUF message type: '.$messageType);

        $response = $this->messageBuilder->buildFo01(
            'StUF001',
            'Onbekend berichttype: '.$messageType,
            'server',
            self::DEFAULT_ZENDER,
            []
        );

        return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
    }//end handleUnknownMessage()

    /**
     * Extract field values from a DOM element.
     *
     * @param \DOMElement|null $element    The parent element.
     * @param string[]         $fieldNames The field names to extract.
     *
     * @return array<string, string> The extracted field values.
     */
    private function extractFields(?\DOMElement $element, array $fieldNames): array
    {
        $result = [];

        if ($element === null) {
            return $result;
        }

        foreach ($fieldNames as $fieldName) {
            $elements = $element->getElementsByTagName($fieldName);
            if ($elements->length > 0 && $elements->item(0) !== null) {
                $result[$fieldName] = $elements->item(0)->textContent ?? '';
            }
        }

        return $result;
    }//end extractFields()

    /**
     * Create a SOAP XML response.
     *
     * @param string $xml        The XML content.
     * @param int    $statusCode The HTTP status code.
     *
     * @return DataDisplayResponse
     *
     * @phpstan-param \OCP\AppFramework\Http::STATUS_* $statusCode
     */
    private function soapResponse(string $xml, int $statusCode=Http::STATUS_OK): DataDisplayResponse
    {
        $response = new DataDisplayResponse($xml, $statusCode);
        $response->addHeader('Content-Type', 'text/xml; charset=utf-8');
        return $response;
    }//end soapResponse()
}//end class
