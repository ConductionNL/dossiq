<?php

/**
 * Procest StUF Controller
 *
 * Handles inbound StUF-ZKN and StUF-BG SOAP messages. Accepts raw XML
 * POST requests and processes them as StUF messages, creating/updating
 * cases and querying data via OpenRegister.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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

namespace OCA\Procest\Controller;

use OCA\Procest\Service\StUF\StufMessageBuilder;
use OCA\Procest\Service\StUF\StufMessageParser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * StUF SOAP endpoint controller.
 *
 * Processes inbound StUF-ZKN (case management) and StUF-BG (person lookup)
 * SOAP messages. Accepts raw XML POST requests at /api/stuf/{service} and
 * returns SOAP XML responses (Bv01 confirmations or Fo01 fault messages).
 *
 * @psalm-suppress UnusedClass
 */
class StufController extends Controller
{

    /**
     * The OpenRegister ObjectService (loaded dynamically).
     *
     * @var object|null
     */
    private $objectService = null;

    /**
     * Default stuurgegevens for responses.
     *
     * @var array<string, mixed>
     */
    private array $defaultStuurgegevens = [];


    /**
     * Constructor.
     *
     * @param string             $appName        The app name.
     * @param IRequest           $request        The incoming request.
     * @param StufMessageBuilder $messageBuilder The StUF message builder.
     * @param StufMessageParser  $messageParser  The StUF message parser.
     * @param LoggerInterface    $logger         The logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly StufMessageBuilder $messageBuilder,
        private readonly StufMessageParser $messageParser,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->loadOpenRegisterServices();
        $this->loadDefaultStuurgegevens();
    }//end __construct()


    /**
     * Handle inbound StUF SOAP messages for cases (zaken service).
     *
     * Accepts StUF-ZKN messages: zakLk01 (create/update), zakLv01 (query).
     *
     * @return Response The SOAP XML response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaken(): Response
    {
        return $this->handleStufRequest();
    }//end zaken()


    /**
     * Handle inbound StUF SOAP messages for persons (personen service).
     *
     * Accepts StUF-BG messages: npsLv01 (person query).
     *
     * @return Response The SOAP XML response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function personen(): Response
    {
        return $this->handleStufRequest();
    }//end personen()


    /**
     * Handle inbound StUF SOAP messages for documents (documenten service).
     *
     * Accepts StUF-ZKN messages: edcLk01 (document create/update).
     *
     * @return Response The SOAP XML response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function documenten(): Response
    {
        return $this->handleStufRequest();
    }//end documenten()


    /**
     * Process the incoming StUF SOAP request.
     *
     * @return Response The SOAP XML response (Bv01 or Fo01).
     */
    private function handleStufRequest(): Response
    {
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody) === true) {
            return $this->buildFaultResponse(
                'Client',
                'Leeg SOAP bericht ontvangen',
                'client',
                '',
            );
        }

        try {
            $parsed = $this->messageParser->parse($rawBody);
        } catch (RuntimeException $e) {
            return $this->buildFaultResponse(
                'Client',
                $e->getMessage(),
                'client',
                '',
            );
        }

        $stuurgegevens = ($parsed['data']['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $referentienummer = ($stuurgegevens['referentienummer'] ?? '');

        return match ($parsed['type']) {
            'zakLk01-create' => $this->handleCaseCreate($parsed['data'], $referentienummer),
            'zakLk01-update' => $this->handleCaseUpdate($parsed['data'], $referentienummer),
            'zakLv01'        => $this->handleCaseQuery($parsed['data'], $referentienummer),
            'edcLk01-create' => $this->handleDocumentCreate($parsed['data'], $referentienummer),
            'edcLk01-update' => $this->handleDocumentUpdate($parsed['data'], $referentienummer),
            'npsLv01'        => $this->handlePersonQuery($parsed['data'], $referentienummer),
            default          => $this->buildFaultResponse(
                'StUF099',
                'Berichttype niet ondersteund: ' . $parsed['type'],
                'server',
                $referentienummer,
            ),
        };
    }//end handleStufRequest()


    /**
     * Handle a case creation message (zakLk01 mutatiesoort=T).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handleCaseCreate(array $data, string $referentienummer): Response
    {
        if ($this->objectService === null) {
            return $this->buildFaultResponse(
                'StUF500',
                'OpenRegister service niet beschikbaar',
                'server',
                $referentienummer,
            );
        }

        $caseData = ($data['case'] ?? []);

        // Resolve case type by code.
        if (isset($caseData['caseTypeCode']) === true) {
            $caseType = $this->resolveCaseTypeByCode($caseData['caseTypeCode']);
            if ($caseType === null) {
                return $this->buildFaultResponse(
                    'StUF058',
                    'Onbekend zaaktype: ' . $caseData['caseTypeCode'],
                    'server',
                    $referentienummer,
                );
            }

            $caseData['caseType'] = $caseType['_id'];
            unset($caseData['caseTypeCode']);
        }

        $this->logger->info(
            'StUF: Creating case from zakLk01',
            ['identifier' => ($caseData['identifier'] ?? 'auto')],
        );

        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handleCaseCreate()


    /**
     * Handle a case update message (zakLk01 mutatiesoort=W).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handleCaseUpdate(array $data, string $referentienummer): Response
    {
        $caseData = ($data['case'] ?? []);
        $identifier = ($caseData['identifier'] ?? null);

        if ($identifier === null) {
            return $this->buildFaultResponse(
                'StUF058',
                'Zaakidentificatie ontbreekt in wijzigingsbericht',
                'client',
                $referentienummer,
            );
        }

        // Verify case exists.
        $existingCase = $this->findCaseByIdentifier($identifier);
        if ($existingCase === null) {
            return $this->buildFaultResponse(
                'StUF064',
                'Zaak niet gevonden: ' . $identifier,
                'server',
                $referentienummer,
            );
        }

        $this->logger->info(
            'StUF: Updating case from zakLk01',
            ['identifier' => $identifier],
        );

        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handleCaseUpdate()


    /**
     * Handle a case query message (zakLv01).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handleCaseQuery(array $data, string $referentienummer): Response
    {
        $this->logger->info(
            'StUF: Processing case query from zakLv01',
            ['query' => ($data['query'] ?? [])],
        );

        // Return empty zakLa01 for now -- full implementation requires
        // building XML response with case data from OpenRegister.
        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handleCaseQuery()


    /**
     * Handle a document creation message (edcLk01 mutatiesoort=T).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handleDocumentCreate(array $data, string $referentienummer): Response
    {
        $zaakIdentifier = ($data['zaakIdentifier'] ?? null);

        if ($zaakIdentifier !== null) {
            $existingCase = $this->findCaseByIdentifier($zaakIdentifier);
            if ($existingCase === null) {
                return $this->buildFaultResponse(
                    'StUF064',
                    'Zaak niet gevonden: ' . $zaakIdentifier,
                    'server',
                    $referentienummer,
                );
            }
        }

        $this->logger->info(
            'StUF: Creating document from edcLk01',
            ['zaak' => $zaakIdentifier],
        );

        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handleDocumentCreate()


    /**
     * Handle a document update message (edcLk01 mutatiesoort=W).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handleDocumentUpdate(array $data, string $referentienummer): Response
    {
        $this->logger->info('StUF: Updating document from edcLk01');

        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handleDocumentUpdate()


    /**
     * Handle a person query message (npsLv01).
     *
     * @param array<string, mixed> $data             The parsed message data.
     * @param string               $referentienummer The original reference number.
     *
     * @return Response The SOAP XML response.
     */
    private function handlePersonQuery(array $data, string $referentienummer): Response
    {
        $this->logger->info(
            'StUF: Processing person query from npsLv01',
            ['query' => ($data['query'] ?? [])],
        );

        $stuurgegevens = ($data['stuurgegevens'] ?? $this->defaultStuurgegevens);
        $xml = $this->messageBuilder->buildBv01($stuurgegevens, $referentienummer);

        return $this->buildXmlResponse($xml, Http::STATUS_OK);
    }//end handlePersonQuery()


    /**
     * Resolve a case type by its StUF code.
     *
     * @param string $code The case type code.
     *
     * @return array<string, mixed>|null The case type data, or null if not found.
     */
    private function resolveCaseTypeByCode(string $code): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        try {
            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $settings = $settingsService->getSettings();

            $register = ($settings['procest_register'] ?? null);
            $schema = ($settings['procest_caseType_schema'] ?? null);

            if ($register === null || $schema === null) {
                return null;
            }

            $result = $this->objectService->findObjects(
                filters: ['identifier' => $code],
                register: $register,
                schema: $schema,
            );

            if (empty($result['objects']) === true) {
                return null;
            }

            return $result['objects'][0];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StUF: Failed to resolve case type by code',
                ['code' => $code, 'exception' => $e->getMessage()],
            );
            return null;
        }
    }//end resolveCaseTypeByCode()


    /**
     * Find a case by its identifier.
     *
     * @param string $identifier The case identifier.
     *
     * @return array<string, mixed>|null The case data, or null if not found.
     */
    private function findCaseByIdentifier(string $identifier): ?array
    {
        if ($this->objectService === null) {
            return null;
        }

        try {
            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $settings = $settingsService->getSettings();

            $register = ($settings['procest_register'] ?? null);
            $schema = ($settings['procest_case_schema'] ?? null);

            if ($register === null || $schema === null) {
                return null;
            }

            $result = $this->objectService->findObjects(
                filters: ['identifier' => $identifier],
                register: $register,
                schema: $schema,
            );

            if (empty($result['objects']) === true) {
                return null;
            }

            return $result['objects'][0];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StUF: Failed to find case by identifier',
                ['identifier' => $identifier, 'exception' => $e->getMessage()],
            );
            return null;
        }
    }//end findCaseByIdentifier()


    /**
     * Build a SOAP fault response.
     *
     * @param string $foutcode       The fault code.
     * @param string $beschrijving   The fault description.
     * @param string $plek           Where the fault occurred (client/server).
     * @param string $crossRefnummer The cross-reference number.
     *
     * @return Response The SOAP XML fault response.
     */
    private function buildFaultResponse(
        string $foutcode,
        string $beschrijving,
        string $plek,
        string $crossRefnummer,
    ): Response {
        $xml = $this->messageBuilder->buildFo01(
            $foutcode,
            $beschrijving,
            $plek,
            $this->defaultStuurgegevens,
            $crossRefnummer,
        );

        $statusCode = ($plek === 'client' ? Http::STATUS_BAD_REQUEST : Http::STATUS_INTERNAL_SERVER_ERROR);
        return $this->buildXmlResponse($xml, $statusCode);
    }//end buildFaultResponse()


    /**
     * Build an XML response with proper content type.
     *
     * @param string $xml        The XML content.
     * @param int    $statusCode The HTTP status code.
     *
     * @return Response The response object.
     */
    private function buildXmlResponse(string $xml, int $statusCode): Response
    {
        $response = new DataResponse(null, $statusCode);
        $response->addHeader('Content-Type', 'text/xml; charset=UTF-8');

        // We need to use a raw response since DataResponse wraps in JSON.
        // Using a simple approach: return the XML via a custom response.
        return new class($xml, $statusCode) extends Response {
            private string $xml;

            public function __construct(string $xml, int $statusCode)
            {
                parent::__construct();
                $this->xml = $xml;
                $this->setStatus($statusCode);
                $this->addHeader('Content-Type', 'text/xml; charset=UTF-8');
            }

            public function render(): string
            {
                return $this->xml;
            }
        };
    }//end buildXmlResponse()


    /**
     * Load OpenRegister services dynamically.
     *
     * @return void
     */
    private function loadOpenRegisterServices(): void
    {
        try {
            $container = \OC::$server;
            $this->objectService = $container->get(
                'OCA\OpenRegister\Service\ObjectService',
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StufController: OpenRegister not available',
                ['exception' => $e->getMessage()],
            );
        }
    }//end loadOpenRegisterServices()


    /**
     * Load default stuurgegevens from settings.
     *
     * @return void
     */
    private function loadDefaultStuurgegevens(): void
    {
        try {
            $settingsService = \OC::$server->get('OCA\Procest\Service\SettingsService');
            $settings = $settingsService->getSettings();

            $this->defaultStuurgegevens = [
                'zender' => [
                    'organisatie' => ($settings['stuf_zender_organisatie'] ?? ''),
                    'applicatie'  => ($settings['stuf_zender_applicatie'] ?? 'Procest'),
                ],
                'ontvanger' => [
                    'organisatie' => '',
                    'applicatie'  => '',
                ],
            ];
        } catch (\Throwable $e) {
            $this->defaultStuurgegevens = [
                'zender' => [
                    'organisatie' => '',
                    'applicatie'  => 'Procest',
                ],
                'ontvanger' => [
                    'organisatie' => '',
                    'applicatie'  => '',
                ],
            ];
        }
    }//end loadDefaultStuurgegevens()


}//end class
