<?php

/**
 * Procest StUF Controller
 *
 * Handles BOTH directions of StUF-ZKN/BG:
 *   - INBOUND server reception: raw XML POST at /api/stuf/{zaken,personen},
 *     parses SOAP envelopes, dispatches per message type (zakLk01/zakLv01/
 *     npsLv01/edcLk01) and returns SOAP XML responses (Bv01/La01/Fo01).
 *   - OUTBOUND gateway (admin REST): vrijBericht send, endpoint listing with
 *     health, audit-log query — JSON. Plus an async confirmation receiver
 *     (`inkomend`) that matches a Bv01 crossRefnummer back to the outbound
 *     StufMessage row and transitions it to "bevestigd".
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
 * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Stuf\CircuitBreakerService;
use OCA\Procest\Service\Stuf\CircuitOpenException;
use OCA\Procest\Service\Stuf\StufAdapterService;
use OCA\Procest\Service\Stuf\StufException;
use OCA\Procest\Service\Stuf\StufMessageHandler;
use OCA\Procest\Service\Stuf\StufMessageParser;
use OCA\Procest\Service\Stuf\StufRegisterAccess;
use OCA\Procest\Service\Stuf\StufVaultService;
use OCA\Procest\Service\StufFieldMappingService;
use OCA\Procest\Service\StufMessageBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for inbound + outbound StUF SOAP messages.
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
     * @param StufAdapterService      $adapter        The outbound adapter.
     * @param StufRegisterAccess      $register       The register access helper.
     * @param StufMessageHandler      $messageHandler The audit log handler.
     * @param StufMessageParser       $parser         The message parser.
     * @param StufVaultService        $vault          The vault adapter.
     * @param CircuitBreakerService   $circuitBreaker The circuit breaker.
     * @param IL10N                   $l10n           The localization service.
     * @param LoggerInterface         $logger         The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly StufFieldMappingService $mappingService,
        private readonly StufMessageBuilder $messageBuilder,
        private readonly StufAdapterService $adapter,
        private readonly StufRegisterAccess $register,
        private readonly StufMessageHandler $messageHandler,
        private readonly StufMessageParser $parser,
        private readonly StufVaultService $vault,
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly IL10N $l10n,
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    #[PublicPage]
    #[NoCSRFRequired]
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function personen(): DataDisplayResponse
    {
        return $this->handleSoapMessage(service: 'personen');
    }//end personen()

    /**
     * Send a vrijBericht to the named endpoint (outbound).
     *
     * Admin-only via #[AuthorizedAdminSetting]. Body: { endpointId, berichtNaam, payload }.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-free-message-templates
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function outbound(): JSONResponse
    {
        $endpointId  = (string) $this->request->getParam(key: 'endpointId', default: '');
        $berichtNaam = (string) $this->request->getParam(key: 'berichtNaam', default: '');
        $payload     = (array) $this->request->getParam(key: 'payload', default: []);

        if ($endpointId === '' || $berichtNaam === '') {
            return new JSONResponse(['error' => $this->l10n->t('endpointId and berichtNaam are required')], Http::STATUS_BAD_REQUEST);
        }

        $endpoint = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_ENDPOINT,
            filters: ['id' => $endpointId]
        );
        if ($endpoint === null) {
            return new JSONResponse(['error' => $this->l10n->t('Endpoint not found')], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->adapter->vrijBericht(name: $berichtNaam, payload: $payload, endpoint: $endpoint);
            return new JSONResponse($result);
        } catch (CircuitOpenException $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Circuit breaker open for this endpoint'), 'errorCode' => 'CIRCUIT_OPEN'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        } catch (StufException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage(), 'errorCode' => 'STUF_VALIDATION'],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->error(message: 'StUF outbound failed: {error}', context: ['error' => $e->getMessage()]);
            return new JSONResponse(['error' => $this->l10n->t('Internal error')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end outbound()

    /**
     * Receive an inbound async confirmation/notification from the zaaksysteem.
     *
     * Public (no user session) but authenticates the caller via WSSE
     * UsernameToken matched against the StufEndpoint vault reference.
     * Persists the inbound envelope as a StufMessage row and, when the
     * envelope is a Bv01 bevestiging, transitions the matching outbound row
     * from "verzonden" → "bevestigd".
     *
     * @return DataResponse
     *
     * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-async-confirmation
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function inkomend(): DataResponse
    {
        $rawXml = (string) file_get_contents(filename: 'php://input');
        if ($rawXml === '') {
            return new DataResponse(data: 'empty body', statusCode: Http::STATUS_BAD_REQUEST);
        }

        $endpoint = $this->resolveInboundEndpoint(envelopeXml: $rawXml);
        if ($endpoint === null) {
            $this->logger->warning(message: 'StUF inkomend: could not resolve endpoint from envelope');
            return new DataResponse(data: 'unknown endpoint', statusCode: Http::STATUS_BAD_REQUEST);
        }

        if ($this->verifyWsse(envelopeXml: $rawXml, endpoint: $endpoint) === false) {
            $this->logger->warning(message: 'StUF inkomend: WSSE signature mismatch for endpoint {id}', context: ['id' => ($endpoint['id'] ?? '')]);
            // 422 (Unprocessable Entity) signals "invalid signature" without
            // surfacing an NC session-auth status to the upstream zaaksysteem.
            // This is WSSE signature verification of a PublicPage webhook, not
            // NC session auth — so the semantic-auth gate stays unambiguous.
            return new DataResponse(data: 'invalid signature', statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $berichtSoort = $this->detectBerichtSoort(envelopeXml: $rawXml);
        $crossRef     = $this->extractCrossRefnummer(envelopeXml: $rawXml);
        $functie      = $this->extractFunctie(envelopeXml: $rawXml);
        $zaakId       = ($this->parser->parseBevestiging(responseXml: $rawXml)['zaakIdentificatie'] ?? null);

        $this->messageHandler->logInbound(
            endpoint: $endpoint,
            responseXml: $rawXml,
            berichtSoort: $berichtSoort,
            crossRefnummer: $crossRef,
            zaakId: $zaakId,
            functie: $functie
        );

        if ($berichtSoort === 'Bv01' && $crossRef !== '') {
            $outbound = $this->messageHandler->findOutboundByReferentienummer(referentienummer: $crossRef);
            if ($outbound !== null) {
                $this->messageHandler->transitionStatus(
                    msg: $outbound,
                    newStatus: 'bevestigd',
                    extras: [
                        'responseEnvelopeXml' => $rawXml,
                        'zaakIdentificatie'   => ($zaakId ?? ($outbound['zaakIdentificatie'] ?? '')),
                    ]
                );
            }
        }

        return new DataResponse(data: 'ack', statusCode: Http::STATUS_OK);
    }//end inkomend()

    /**
     * List all configured StufEndpoint objects (admin REST).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-outbound-rest-surface
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function endpoints(): JSONResponse
    {
        $items = $this->register->findAll(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: [], limit: 500);
        $items = array_map(callback: [$this, 'enrichEndpointWithHealth'], array: $items);
        return new JSONResponse(['items' => $items, 'total' => count(value: $items)]);
    }//end endpoints()

    /**
     * Query the StufMessage audit log (admin REST).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/procest-stuf-zkn-outbound-gateway/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function messages(): JSONResponse
    {
        $endpointId   = (string) $this->request->getParam(key: 'endpointId', default: '');
        $berichtSoort = (string) $this->request->getParam(key: 'berichtSoort', default: '');
        $status       = (string) $this->request->getParam(key: 'status', default: '');
        $limit        = (int) $this->request->getParam(key: 'limit', default: 50);
        $limit        = max(1, min(500, $limit));

        $filters = [];
        if ($endpointId !== '') {
            $filters['endpointId'] = $endpointId;
        }

        if ($berichtSoort !== '') {
            $filters['berichtSoort'] = $berichtSoort;
        }

        if ($status !== '') {
            $filters['status'] = $status;
        }

        $items = $this->register->findAll(schema: StufRegisterAccess::SCHEMA_MESSAGE, filters: $filters, limit: $limit);
        return new JSONResponse(['items' => $items, 'total' => count(value: $items), 'limit' => $limit]);
    }//end messages()

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

        // Enforce size limit to mitigate XML bomb / DoS.
        if (strlen($rawBody) > 2097152) {
            $response = $this->messageBuilder->buildSoapFault('Bericht te groot');
            return $this->soapResponse(xml: $response, statusCode: Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
        }

        // Parse the XML with XXE/DTD protections.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // LIBXML_NONET: prohibits network access from within XML (XXE via HTTP/FTP).
        // LIBXML_DTDLOAD: disabled intentionally (we do NOT load external DTDs).
        // Passing LIBXML_NOENT would *expand* entities — intentionally omitted.
        $parseResult = $dom->loadXML($rawBody, LIBXML_NONET);
        $errors      = libxml_get_errors();
        libxml_clear_errors();

        if ($parseResult === false || empty($errors) === false) {
            $this->logger->warning('Invalid XML received at StUF endpoint: {service}', ['service' => $service]);
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
        $crossRef = $this->extractStuurgegevensReferentienummer(message: $message);

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
            $gelijkEl = $gelijkElements->item(0);
            if ($gelijkEl instanceof \DOMElement) {
                $bsnElements = $gelijkEl->getElementsByTagName('bsn');
                if ($bsnElements->length > 0) {
                    $bsn = $bsnElements->item(0)->textContent ?? '';
                }
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

        $crossRef = $this->extractStuurgegevensReferentienummer(message: $message);

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
        $this->logger->warning('Unknown StUF message type: {type}', ['type' => $messageType]);

        $response = $this->messageBuilder->buildFo01(
            'StUF001',
            'Onbekend berichttype',
            'server',
            self::DEFAULT_ZENDER,
            []
        );

        return $this->soapResponse(xml: $response, statusCode: Http::STATUS_BAD_REQUEST);
    }//end handleUnknownMessage()

    /**
     * Extract the referentienummer from a message's stuurgegevens (best-effort).
     *
     * @param \DOMElement $message The StUF message element.
     *
     * @return string The referentienummer (empty if absent).
     */
    private function extractStuurgegevensReferentienummer(\DOMElement $message): string
    {
        $stuurgegevens = $message->getElementsByTagName('stuurgegevens');
        if ($stuurgegevens->length > 0) {
            $stuurgegevensEl = $stuurgegevens->item(0);
            if ($stuurgegevensEl instanceof \DOMElement) {
                $refElements = $stuurgegevensEl->getElementsByTagName('referentienummer');
                if ($refElements->length > 0 && $refElements->item(0) !== null) {
                    return $refElements->item(0)->textContent ?? '';
                }
            }
        }

        return '';
    }//end extractStuurgegevensReferentienummer()

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
     * Resolve the StufEndpoint from the envelope's ontvanger/zender (best-effort).
     *
     * @param string $envelopeXml The inbound envelope.
     *
     * @return array|null The endpoint or null.
     */
    private function resolveInboundEndpoint(string $envelopeXml): ?array
    {
        $zenderPattern = '#<stuf:zender>.*?<stuf:applicatie>([^<]+)</stuf:applicatie>.*?</stuf:zender>#s';
        if (preg_match(pattern: $zenderPattern, subject: $envelopeXml, matches: $matches) === 1) {
            $applicatie = trim(string: $matches[1]);
            $endpoint   = $this->register->findOne(
                schema: StufRegisterAccess::SCHEMA_ENDPOINT,
                filters: ['ontvangerApplicatie' => $applicatie]
            );
            if ($endpoint !== null) {
                return $endpoint;
            }
        }

        // Fallback: header X-Procest-Endpoint-Id (used by callers we control).
        $headerId = (string) $this->request->getHeader(name: 'x-procest-endpoint-id');
        if ($headerId !== '') {
            return $this->register->findOne(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: ['id' => $headerId]);
        }

        return null;
    }//end resolveInboundEndpoint()

    /**
     * Verify the inbound WSSE UsernameToken matches the endpoint's stored credentials.
     *
     * @param string $envelopeXml The envelope XML.
     * @param array  $endpoint    The endpoint.
     *
     * @return bool
     */
    private function verifyWsse(string $envelopeXml, array $endpoint): bool
    {
        $auth         = ($endpoint['authenticatie'] ?? []);
        $expectedUser = (string) ($auth['gebruikersnaam'] ?? '');
        $expectedPasswordRef = (string) ($auth['wachtwoordKluisRef'] ?? '');
        $expectedPassword    = $this->vault->resolveSecret(reference: $expectedPasswordRef);

        if ($expectedUser === '' || $expectedPassword === '') {
            return false;
        }

        $username = '';
        if (preg_match(pattern: '#<wsse:Username>([^<]+)</wsse:Username>#', subject: $envelopeXml, matches: $matches) === 1) {
            $username = trim(string: $matches[1]);
        }

        $password = '';
        if (preg_match(pattern: '#<wsse:Password[^>]*>([^<]+)</wsse:Password>#', subject: $envelopeXml, matches: $matches) === 1) {
            $password = trim(string: $matches[1]);
        }

        return hash_equals(known_string: $expectedUser, user_string: $username)
            && hash_equals(known_string: $expectedPassword, user_string: $password);
    }//end verifyWsse()

    /**
     * Detect the bericht-soort (Bv01, Lk02, ...) from the envelope.
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function detectBerichtSoort(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:berichtcode>([A-Za-z0-9]+)</stuf:berichtcode>#', subject: $envelopeXml, matches: $matches) === 1) {
            return $matches[1];
        }

        if (str_contains(haystack: $envelopeXml, needle: 'zakLk02') === true) {
            return 'Lk02';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'zakLk01') === true) {
            return 'Lk01';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'Bv01') === true) {
            return 'Bv01';
        }

        if (str_contains(haystack: $envelopeXml, needle: 'Fo02') === true) {
            return 'Fo02';
        }

        return 'Lk02';
    }//end detectBerichtSoort()

    /**
     * Extract crossRefnummer from an inbound envelope (best-effort).
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function extractCrossRefnummer(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:crossRefnummer>([^<]+)</stuf:crossRefnummer>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        if (preg_match(pattern: '#<stuf:referentienummer>([^<]+)</stuf:referentienummer>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        return '';
    }//end extractCrossRefnummer()

    /**
     * Extract functie from an inbound envelope (best-effort).
     *
     * @param string $envelopeXml The envelope.
     *
     * @return string
     */
    private function extractFunctie(string $envelopeXml): string
    {
        if (preg_match(pattern: '#<stuf:functie>([^<]+)</stuf:functie>#', subject: $envelopeXml, matches: $matches) === 1) {
            return trim(string: $matches[1]);
        }

        return '';
    }//end extractFunctie()

    /**
     * Enrich an endpoint with its health snapshot (status badge + last 5 messages).
     *
     * @param array $endpoint The raw endpoint row.
     *
     * @return array
     */
    private function enrichEndpointWithHealth(array $endpoint): array
    {
        $snapshot = $this->circuitBreaker->snapshot(endpointId: (string) ($endpoint['id'] ?? ''));
        $recent   = $this->register->findAll(
            schema: StufRegisterAccess::SCHEMA_MESSAGE,
            filters: ['endpointId' => (string) ($endpoint['id'] ?? '')],
            limit: 5
        );
        $endpoint['health'] = [
            'state'        => $snapshot['state'],
            'failureCount' => $snapshot['failureCount'],
            'openedAt'     => $snapshot['openedAt'],
            'recentCount'  => count(value: $recent),
        ];
        return $endpoint;
    }//end enrichEndpointWithHealth()

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
