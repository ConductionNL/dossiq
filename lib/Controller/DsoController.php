<?php

/**
 * DSO Controller
 *
 * Thin HTTP controller for the DSO Omgevingsloket integration. Delegates all
 * business logic to the appropriate services. Exposes a dashboard view and
 * workflow actions (status transition, beschikking generation, samenwerking
 * initiation/response, doorsturen) for omgevingsvergunning zaken.
 *
 * All endpoints require authentication (@NoAdminRequired) and carry per-object
 * authorization guards (ADR-005 Rule 3) to prevent IDOR.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller exposing DSO Omgevingsloket endpoints.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */
class DsoController extends Controller
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param string                       $appName            The app name
     * @param IRequest                     $request            The HTTP request
     * @param DsoCaseService               $dsoCaseService     The DSO case service
     * @param BeschikkingGenerationService $beschikkingService The beschikking generation service
     * @param SamenwerkverzoekService      $samenwerkService   The samenwerkverzoek service
     * @param SettingsService              $settingsService    The settings service (config + ObjectService bridge)
     * @param IUserSession                 $userSession        The user session
     * @param IEventDispatcher             $eventDispatcher    The event dispatcher
     * @param LoggerInterface              $logger             The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DsoCaseService $dsoCaseService,
        private readonly BeschikkingGenerationService $beschikkingService,
        private readonly SamenwerkverzoekService $samenwerkService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return a filtered list of DSO omgevingsvergunning cases for the dashboard.
     *
     * Reads filter params from the query string and returns matching cases.
     * No per-object auth required for listing: all authenticated users may
     * view the dashboard overview.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function dashboard(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $activiteitgroep   = $this->request->getParam('activiteitgroep', '');
        $regelkwalificatie = $this->request->getParam('regelkwalificatie', '');
        $status            = $this->request->getParam('status', '');
        $locatie           = $this->request->getParam('locatie', '');
        $gemeenteCode      = $this->request->getParam('gemeenteCode', '');
        $procedureType     = $this->request->getParam('procedureType', '');

        $params = ['caseType' => 'omgevingsvergunning'];

        if ($status !== '') {
            $params['status'] = $status;
        }

        if ($procedureType !== '') {
            $params['procedureType'] = $procedureType;
        }

        if ($gemeenteCode !== '') {
            $params['gemeenteCode'] = $gemeenteCode;
        }

        $params['_limit']  = 100;
        $params['_offset'] = 0;

        try {
            $objectService = $this->getObjectServiceOrFail();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
            }

            // Resolve register and schema via the config helper.
            $register   = '';
            $caseSchema = '';

            // Resolve via container — inject via IAppConfig.
            $register   = $this->resolveConfigValue(key: 'register');
            $caseSchema = $this->resolveConfigValue(key: 'case_schema');

            if ($register === '' || $caseSchema === '') {
                return new JSONResponse(['error' => 'Case register not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
            }

            $zakenList = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                filters: $params
            );

            $filtered = $this->applyInMemoryFilters(
                zaken: $zakenList,
                activiteitgroep: (string) $activiteitgroep,
                regelkwalificatie: (string) $regelkwalificatie,
                locatie: (string) $locatie
            );

            return new JSONResponse(['results' => $filtered, 'count' => count($filtered)]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest DsoController::dashboard failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not load dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end dashboard()

    /**
     * Transition the status of a DSO case.
     *
     * Reads newStatus, besluitdatum, and toelichting from the request body.
     * Authorizes the mutation (per-object IDOR guard) before delegating to
     * DsoCaseService::transitionStatus().
     *
     * @param string $caseId The UUID of the case to transition
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function transitionStatus(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body      = $this->readJsonBody();
        $newStatus = (string) ($body['newStatus'] ?? '');

        if (isset($body['besluitdatum']) === true) {
            $besluitdatum = (string) $body['besluitdatum'];
        } else {
            $besluitdatum = null;
        }

        if (isset($body['toelichting']) === true) {
            $toelichting = (string) $body['toelichting'];
        } else {
            $toelichting = null;
        }

        if ($newStatus === '') {
            return new JSONResponse(['error' => 'newStatus is required'], Http::STATUS_BAD_REQUEST);
        }

        $allowedStatuses = ['ingediend', 'in_behandeling', 'verleend', 'geweigerd', 'ingetrokken'];
        if (in_array(needle: $newStatus, haystack: $allowedStatuses, strict: true) === false) {
            return new JSONResponse(['error' => 'Invalid status value'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $zaak = $this->loadZaak(caseId: $caseId);
            if ($zaak === null) {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            $this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

            $updated = $this->dsoCaseService->transitionStatus(
                zaakId: $caseId,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum,
                toelichting: $toelichting,
                userId: $user->getUID()
            );

            return new JSONResponse($updated);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Not authorized') {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $this->logger->error('Procest DsoController::transitionStatus failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not transition status'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end transitionStatus()

    /**
     * Generate a beschikking document for a DSO case.
     *
     * Reads outcome and motivation from the request body. Authorizes the
     * mutation before delegating to BeschikkingGenerationService.
     *
     * @param string $caseId The UUID of the case
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function generateBeschikking(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body       = $this->readJsonBody();
        $outcome    = (string) ($body['outcome'] ?? '');
        $motivation = (string) ($body['motivation'] ?? '');

        if ($outcome === '') {
            return new JSONResponse(['error' => 'outcome is required'], Http::STATUS_BAD_REQUEST);
        }

        $allowedOutcomes = ['verleend', 'geweigerd'];
        if (in_array(needle: $outcome, haystack: $allowedOutcomes, strict: true) === false) {
            return new JSONResponse(['error' => 'Invalid outcome value'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $zaak = $this->loadZaak(caseId: $caseId);
            if ($zaak === null) {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            $this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

            $result = $this->beschikkingService->generateBeschikking(
                zaakId: $caseId,
                outcome: $outcome,
                motivation: $motivation
            );

            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Not authorized') {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $this->logger->error('Procest DsoController::generateBeschikking failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not generate beschikking'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end generateBeschikking()

    /**
     * Initiate a samenwerking request for a DSO case.
     *
     * Reads aangezochtBevoegdGezag and rationale from the request body.
     * Authorizes the mutation before delegating to SamenwerkverzoekService.
     *
     * @param string $caseId The UUID of the case
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function initiateSamenwerking(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->readJsonBody();
        $aangezochtBevoegdGezag = (string) ($body['aangezochtBevoegdGezag'] ?? '');
        $rationale = (string) ($body['rationale'] ?? '');

        if ($aangezochtBevoegdGezag === '') {
            return new JSONResponse(
                ['error' => 'aangezochtBevoegdGezag is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $zaak = $this->loadZaak(caseId: $caseId);
            if ($zaak === null) {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            $this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

            $samenwerkverzoek = $this->samenwerkService->initiateSamenwerking(
                zaakId: $caseId,
                aangezochtBevoegdGezag: $aangezochtBevoegdGezag,
                rationale: $rationale
            );

            return new JSONResponse($samenwerkverzoek, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Not authorized') {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $this->logger->error('Procest DsoController::initiateSamenwerking failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not initiate samenwerking'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end initiateSamenwerking()

    /**
     * Respond to an existing samenwerkverzoek.
     *
     * Reads accept and advies from the request body. Authorizes the mutation
     * before delegating to SamenwerkverzoekService::respondToSamenwerking().
     *
     * @param string $samenwerkId The UUID of the samenwerkverzoek
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function respondSamenwerking(string $samenwerkId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body   = $this->readJsonBody();
        $accept = (bool) ($body['accept'] ?? false);
        $advies = (string) ($body['advies'] ?? '');

        try {
            $samenwerkverzoek = $this->loadSamenwerkverzoek(samenwerkId: $samenwerkId);
            if ($samenwerkverzoek === null) {
                return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
            }

            $this->samenwerkService->authorizeSamenwerkMutation(
                samenwerk: $samenwerkverzoek,
                user: $user
            );

            $updated = $this->samenwerkService->respondToSamenwerking(
                samenwerkId: $samenwerkId,
                accept: $accept,
                advies: $advies
            );

            return new JSONResponse($updated);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Not authorized') {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $this->logger->error('Procest DsoController::respondSamenwerking failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not respond to samenwerking'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end respondSamenwerking()

    /**
     * Doorsturen: forward a DSO case to another bevoegd gezag.
     *
     * Reads targetBevoegdGezag and reden from the request body, authorizes the
     * mutation, and dispatches a VergunningDoorgestuurd generic event for
     * downstream listeners.
     *
     * @param string $caseId The UUID of the case to forward
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
     */
    #[NoAdminRequired]
    public function doorsturen(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->readJsonBody();
        $targetBevoegdGezag = (string) ($body['targetBevoegdGezag'] ?? '');
        $reden = (string) ($body['reden'] ?? '');

        if ($targetBevoegdGezag === '') {
            return new JSONResponse(
                ['error' => 'targetBevoegdGezag is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $zaak = $this->loadZaak(caseId: $caseId);
            if ($zaak === null) {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            $this->dsoCaseService->authorizeZaakMutation(zaak: $zaak, user: $user);

            $event = new GenericEvent(
                subject: $zaak,
                arguments: [
                    'caseId'             => $caseId,
                    'targetBevoegdGezag' => $targetBevoegdGezag,
                    'reden'              => $reden,
                    'userId'             => $user->getUID(),
                ]
            );
            $this->eventDispatcher->dispatch(
                eventName: 'OCA\Procest\Event\VergunningDoorgestuurd',
                event: $event
            );

            return new JSONResponse(
                [
                    'status'             => 'doorgestuurd',
                    'caseId'             => $caseId,
                    'targetBevoegdGezag' => $targetBevoegdGezag,
                ]
            );
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Not authorized') {
                return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $this->logger->error('Procest DsoController::doorsturen failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Could not doorsturen'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end doorsturen()

    /**
     * Decode a JSON request body safely.
     *
     * @return array<string,mixed>
     */
    private function readJsonBody(): array
    {
        // OCP\IRequest::getContent() is protected on the concrete OC
        // request; read raw payload from php://input instead.
        $content = (string) file_get_contents('php://input');
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end readJsonBody()

    /**
     * Load a zaak by ID from the ObjectService.
     *
     * Returns null when the zaak does not exist or the service is unavailable.
     *
     * @param string $caseId The zaak UUID
     *
     * @return array<string,mixed>|null
     */
    private function loadZaak(string $caseId): ?array
    {
        try {
            $objectService = $this->getObjectServiceOrFail();
            if ($objectService === null) {
                return null;
            }

            $register   = $this->resolveConfigValue(key: 'register');
            $caseSchema = $this->resolveConfigValue(key: 'case_schema');

            if ($register === '' || $caseSchema === '') {
                return null;
            }

            return $this->findObjectAsArray(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoController: could not load zaak '.$caseId.': '.$e->getMessage()
            );
            return null;
        }//end try
    }//end loadZaak()

    /**
     * Load a samenwerkverzoek by ID from the ObjectService.
     *
     * @param string $samenwerkId The samenwerkverzoek UUID
     *
     * @return array<string,mixed>|null
     */
    private function loadSamenwerkverzoek(string $samenwerkId): ?array
    {
        try {
            $objectService = $this->getObjectServiceOrFail();
            if ($objectService === null) {
                return null;
            }

            $register = $this->resolveConfigValue(key: 'register');
            $samenwerkverzoekSchema = $this->resolveConfigValue(key: 'dso_samenwerkverzoek_schema');

            if ($register === '' || $samenwerkverzoekSchema === '') {
                $samenwerkverzoekSchema = 'samenwerkverzoek';
            }

            return $this->findObjectAsArray(
                objectService: $objectService,
                register: $register,
                schema: $samenwerkverzoekSchema,
                id: $samenwerkId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest DsoController: could not load samenwerkverzoek '.$samenwerkId.': '.$e->getMessage()
            );
            return null;
        }//end try
    }//end loadSamenwerkverzoek()

    /**
     * Get the ObjectService from the DI container; returns null when unavailable.
     *
     * @return object|null
     */
    private function getObjectServiceOrFail(): ?object
    {
        return $this->settingsService->getObjectService();
    }//end getObjectServiceOrFail()

    /**
     * Resolve an app config value from the Nextcloud app config.
     *
     * @param string $key Config key
     *
     * @return string Config value or empty string
     */
    private function resolveConfigValue(string $key): string
    {
        return $this->settingsService->getConfigValue(key: $key);
    }//end resolveConfigValue()

    /**
     * Apply in-memory filters that cannot be pushed to ObjectService params.
     *
     * @param array<int,mixed> $zaken             The zaken array (elements come from ObjectService and are not guaranteed to be arrays)
     * @param string           $activiteitgroep   Filter by activiteitgroep
     * @param string           $regelkwalificatie Filter by regelkwalificatie
     * @param string           $locatie           Filter by locatie substring
     *
     * @return array<int,array<string,mixed>>
     */
    private function applyInMemoryFilters(
        array $zaken,
        string $activiteitgroep,
        string $regelkwalificatie,
        string $locatie,
    ): array {
        if ($activiteitgroep === '' && $regelkwalificatie === '' && $locatie === '') {
            return $zaken;
        }

        $result = [];
        foreach ($zaken as $zaak) {
            if (is_array($zaak) === false) {
                continue;
            }

            if ($locatie !== '' && str_contains((string) ($zaak['locatie'] ?? ''), $locatie) === false) {
                continue;
            }

            $result[] = $zaak;
        }

        return $result;
    }//end applyInMemoryFilters()
}//end class
