<?php

/**
 * Procest DSO Controller
 *
 * REST endpoints for the VTH dashboard, DSO case lifecycle transitions,
 * beschikking generation, samenwerkverzoek initiation/response, and doorstuur.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for DSO VTH dashboard and case lifecycle operations.
 *
 * @psalm-suppress UnusedClass
 */
class DsoController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                       $appName                      The app name
     * @param IRequest                     $request                      The HTTP request
     * @param DsoCaseService               $dsoCaseService               DSO case service
     * @param BeschikkingGenerationService $beschikkingGenerationService Beschikking service
     * @param SamenwerkverzoekService      $samenwerkverzoekService      Samenwerking service
     * @param SettingsService              $settingsService              Settings service
     * @param IUserSession                 $userSession                  User session
     * @param LoggerInterface              $logger                       Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DsoCaseService $dsoCaseService,
        private readonly BeschikkingGenerationService $beschikkingGenerationService,
        private readonly SamenwerkverzoekService $samenwerkverzoekService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return VTH dashboard data with filters.
     *
     * Supports filters: activiteitgroep, regelkwalificatie, status,
     * locatie, gemeenteCode, procedureType, deadlineRange.
     *
     * @return JSONResponse Paginated list of omgevingsvergunning zaken
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function dashboard(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        $params = [
            '_limit'  => (int) ($this->request->getParam('_limit') ?? 30),
            '_offset' => (int) ($this->request->getParam('_offset') ?? 0),
        ];

        // Apply optional filters.
        foreach (['status', 'procedureType', 'bevoegdGezag'] as $field) {
            $val = $this->request->getParam($field);
            if ($val !== null && $val !== '') {
                $params[$field] = $val;
            }
        }

        // Only return DSO cases (those with vergunningaanvraagRef set).
        $params['vergunningaanvraagRef'] = ['_exists' => true];

        try {
            $zaken = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: $params
            );

            if (is_array($zaken) === false) {
                $zaken = [];
            }

            $results = array_map(
                static function ($z) {
                    if (is_object($z) === true && method_exists($z, 'jsonSerialize') === true) {
                        return $z->jsonSerialize();
                    }

                    return (array) $z;
                },
                $zaken
            );

            return new JSONResponse(['results' => $results, 'count' => count($results)]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DsoController::dashboard failed: '.$e->getMessage(),
            );
            return new JSONResponse(['error' => 'Failed to load dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end dashboard()

    /**
     * Transition the status of a DSO zaak.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse Updated zaak or error
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function transitionStatus(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // Per-object authorization: user must be assignee, admin, or behandelaar.
        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        try {
            $zaak = $objectService->getObject(
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaakArr = $zaak->jsonSerialize();
        } else {
            $zaakArr = (array) $zaak;
        }

        $assignee = (string) ($zaakArr['assignee'] ?? '');

        if ($assignee !== '' && $assignee !== $user->getUID()) {
            return new JSONResponse(['error' => 'Not authorized to transition this case'], Http::STATUS_FORBIDDEN);
        }

        $body      = $this->getRequestBody();
        $newStatus = (string) ($body['status'] ?? '');
        if ($newStatus === '') {
            return new JSONResponse(['error' => 'status is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
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

            $updated = $this->dsoCaseService->transitionStatus(
                zaakId: $caseId,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum,
                toelichting: $toelichting,
                userId: $user->getUID()
            );
            return new JSONResponse($updated);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController::transitionStatus: '.$e->getMessage());
            return new JSONResponse(['error' => 'Transition failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end transitionStatus()

    /**
     * Generate a beschikking document for a DSO zaak.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse Bijlage metadata or error
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function generateBeschikking(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        try {
            $zaak = $objectService->getObject(
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaakArr = $zaak->jsonSerialize();
        } else {
            $zaakArr = (array) $zaak;
        }

        $assignee = (string) ($zaakArr['assignee'] ?? '');

        if ($assignee !== '' && $assignee !== $user->getUID()) {
            return new JSONResponse(['error' => 'Not authorized for this case'], Http::STATUS_FORBIDDEN);
        }

        $body       = $this->getRequestBody();
        $outcome    = (string) ($body['outcome'] ?? '');
        $motivation = (string) ($body['motivation'] ?? '');

        if ($outcome === '') {
            return new JSONResponse(['error' => 'outcome is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $bijlage = $this->beschikkingGenerationService->generateBeschikking(
                zaakId: $caseId,
                outcome: $outcome,
                motivation: $motivation
            );
            return new JSONResponse($bijlage);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController::generateBeschikking: '.$e->getMessage());
            return new JSONResponse(['error' => 'Beschikking generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end generateBeschikking()

    /**
     * Initiate a samenwerkverzoek for a DSO zaak.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse Created samenwerkverzoek or error
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function initiateSamenwerking(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        try {
            $zaak = $objectService->getObject(
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaakArr = $zaak->jsonSerialize();
        } else {
            $zaakArr = (array) $zaak;
        }

        $assignee = (string) ($zaakArr['assignee'] ?? '');

        if ($assignee !== '' && $assignee !== $user->getUID()) {
            return new JSONResponse(['error' => 'Not authorized for this case'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getRequestBody();
        $aangezochtBevoegdGezag = (string) ($body['aangezochtBevoegdGezag'] ?? '');
        $rationale = (string) ($body['rationale'] ?? '');

        if ($aangezochtBevoegdGezag === '') {
            return new JSONResponse(['error' => 'aangezochtBevoegdGezag is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $sw = $this->samenwerkverzoekService->initiateSamenwerking(
                zaakId: $caseId,
                aangezochtBevoegdGezag: $aangezochtBevoegdGezag,
                rationale: $rationale
            );
            return new JSONResponse($sw, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController::initiateSamenwerking: '.$e->getMessage());
            return new JSONResponse(['error' => 'Samenwerking initiation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end initiateSamenwerking()

    /**
     * Accept or reject a samenwerkverzoek.
     *
     * @param string $id UUID of the samenwerkverzoek
     *
     * @return JSONResponse Updated samenwerkverzoek or error
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function respondSamenwerking(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // Per-object authorization: load samenwerkverzoek and verify responding party.
        $register = $this->settingsService->getConfigValue(key: 'register');
        $swSchema = $this->settingsService->getConfigValue(key: 'dso_samenwerkverzoek_schema');
        if ($swSchema !== '') {
            $schema = $swSchema;
        } else {
            $schema = 'samenwerkverzoek';
        }

        try {
            $sw = $objectService->getObject(
                register: $register,
                schema: $schema,
                id: $id
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_object($sw) === true && method_exists($sw, 'jsonSerialize') === true) {
            $swArr = $sw->jsonSerialize();
        } else {
            $swArr = (array) $sw;
        }

        if (is_array($swArr) === false || empty($swArr) === true) {
            return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
        }

        $body   = $this->getRequestBody();
        $accept = (bool) ($body['accept'] ?? false);
        if (isset($body['advies']) === true) {
            $advies = (string) $body['advies'];
        } else {
            $advies = null;
        }

        try {
            $updated = $this->samenwerkverzoekService->respondToSamenwerking(
                samenwerkId: $id,
                accept: $accept,
                advies: $advies
            );
            return new JSONResponse($updated);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController::respondSamenwerking: '.$e->getMessage());
            return new JSONResponse(['error' => 'Response failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end respondSamenwerking()

    /**
     * Forward (doorsturen) a DSO zaak to another bevoegd gezag.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse Confirmation or error
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function doorsturen(string $caseId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        try {
            $zaak = $objectService->getObject(
                register: $register,
                schema: $caseSchema,
                id: $caseId
            );
        } catch (\Throwable) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $zaakArr = $zaak->jsonSerialize();
        } else {
            $zaakArr = (array) $zaak;
        }

        $assignee = (string) ($zaakArr['assignee'] ?? '');

        if ($assignee !== '' && $assignee !== $user->getUID()) {
            return new JSONResponse(['error' => 'Not authorized for this case'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getRequestBody();
        $doelBevoegdGezag = (string) ($body['doelBevoegdGezag'] ?? '');
        $reden            = (string) ($body['reden'] ?? '');

        if ($doelBevoegdGezag === '') {
            return new JSONResponse(['error' => 'doelBevoegdGezag is required'], Http::STATUS_BAD_REQUEST);
        }

        // Record doorstuur in activity log.
        $rawActivity = $zaakArr['activity'] ?? null;
        if (is_array($rawActivity) === true) {
            $activity = $rawActivity;
        } else {
            $activity = [];
        }

        $activity[]          = [
            'type'             => 'doorgestuurNaar',
            'userId'           => $user->getUID(),
            'timestamp'        => date('c'),
            'doelBevoegdGezag' => $doelBevoegdGezag,
            'reden'            => $reden,
        ];
        $zaakArr['activity'] = $activity;
        $zaakArr['bevoegdGezag'] = $doelBevoegdGezag;

        try {
            $updated = $objectService->saveObject(
                register: $register,
                schema: $caseSchema,
                object: $zaakArr
            );

            return new JSONResponse(
                    [
                        'message'          => 'Zaak doorgestuurd.',
                        'doelBevoegdGezag' => $doelBevoegdGezag,
                        'zaakId'           => $caseId,
                    ]
                    );
        } catch (\Throwable $e) {
            $this->logger->error('DsoController::doorsturen: '.$e->getMessage());
            return new JSONResponse(['error' => 'Doorsturen failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end doorsturen()

    /**
     * Parse the JSON request body.
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode(json: (string) $content, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRequestBody()
}//end class
