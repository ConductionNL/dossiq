<?php

/**
 * Procest DSO Controller
 *
 * REST endpoints for the VTH dashboard and DSO-specific operations:
 * dashboard listing, status transitions, beschikking generation,
 * samenwerkverzoek initiation/response, and doorsturen.
 *
 * All endpoints require authentication (#[NoAdminRequired]) and carry
 * per-object authorization checks per ADR-005 Rule 3 / OWASP A01:2021.
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\BeschikkingGenerationService;
use OCA\Procest\Service\DsoCaseService;
use OCA\Procest\Service\SamenwerkverzoekService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles REST requests for DSO-related VTH operations.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
 */
class DsoController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                       $appName            The app name
     * @param IRequest                     $request            The HTTP request
     * @param DsoCaseService               $dsoCaseService     The DSO case service
     * @param BeschikkingGenerationService $beschikkingService The beschikking service
     * @param SamenwerkverzoekService      $samenwerkService   The samenwerking service
     * @param SettingsService              $settingsService    The settings service
     * @param IUserSession                 $userSession        The current user session
     * @param IGroupManager                $groupManager       The group manager
     * @param IEventDispatcher             $dispatcher         The event dispatcher
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
        private readonly IGroupManager $groupManager,
        private readonly IEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get the VTH dashboard data with filters.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    #[NoAdminRequired]
    public function dashboard(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(['error' => 'OpenRegister not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return new JSONResponse(['error' => 'Register not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // Build filter params from query string.
        $params = [
            '_limit'  => max(1, min(100, (int) ($this->request->getParam('_limit', '30')))),
            '_offset' => max(0, (int) ($this->request->getParam('_offset', '0'))),
        ];

        $filterKeys = ['activiteitgroep', 'regelkwalificatie', 'dsoStatus', 'locatie', 'gemeenteCode', 'procedureType'];
        foreach ($filterKeys as $key) {
            $val = $this->request->getParam($key);
            if ($val !== null && $val !== '') {
                $params[$key] = $val;
            }
        }

        // Only return zaken that have a DSO vergunningaanvraagRef (omgevingsvergunning cases).
        $params['vergunningaanvraagRef'] = ['neq' => ''];

        $deadlineRange = $this->request->getParam('deadlineRange');
        if ($deadlineRange !== null && $deadlineRange !== '') {
            $today = date('Y-m-d');
            if ($deadlineRange === 'overdue') {
                $params['deadlineDatum'] = ['lt' => $today];
            } else if ($deadlineRange === 'today') {
                $params['deadlineDatum'] = $today;
            } else if ($deadlineRange === 'week') {
                $params['deadlineDatum'] = ['gte' => $today, 'lte' => date('Y-m-d', strtotime('+7 days'))];
            }
        }

        try {
            $result = $objectService->findObjects(
                register: $register,
                schema: $caseSchema,
                params: $params,
            );

            $items = [];
            if (is_array($result) === true) {
                $items = $result;
            } else if (is_object($result) === true && method_exists($result, 'getResults') === true) {
                $items = $result->getResults() ?? [];
            }

            return new JSONResponse(['results' => $items, 'total' => count($items)]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DsoController: dashboard query failed',
                ['error' => $e->getMessage(), 'app' => Application::APP_ID],
            );
            return new JSONResponse(['error' => 'Could not load dashboard data'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end dashboard()

    /**
     * Transition the DSO status of a case.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
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

        $body      = $this->readJsonBody();
        $newStatus = (string) ($body['newStatus'] ?? '');
        if ($newStatus === '') {
            return new JSONResponse(['error' => 'newStatus is required'], Http::STATUS_BAD_REQUEST);
        }

        $allowedStatuses = ['ingediend', 'in_behandeling', 'verleend', 'geweigerd', 'ingetrokken'];
        if (in_array($newStatus, $allowedStatuses, true) === false) {
            return new JSONResponse(['error' => 'Invalid status value'], Http::STATUS_BAD_REQUEST);
        }

        // Per-object auth: only assignee or admin may transition.
        $zaak = $this->fetchZaak(zaakId: $caseId);
        if ($zaak === null) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeZaakMutation(zaak: $zaak, user: $user);

        $besluitdatum = null;
        if (isset($body['besluitdatum']) === true) {
            $besluitdatum = (string) $body['besluitdatum'];
        }

        $toelichting = null;
        if (isset($body['toelichting']) === true) {
            $toelichting = (string) $body['toelichting'];
        }

        try {
            $updated = $this->dsoCaseService->transitionStatus(
                zaakId: $caseId,
                newStatus: $newStatus,
                besluitdatum: $besluitdatum,
                toelichting: $toelichting,
                userId: $user->getUID(),
            );
            return new JSONResponse($updated);
        } catch (\RuntimeException $e) {
            $code   = $e->getMessage();
            $status = match ($code) {
                'zaak_not_found' => Http::STATUS_NOT_FOUND,
                default          => Http::STATUS_BAD_REQUEST,
            };

            return new JSONResponse(['error' => 'Could not transition status'], $status);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController: transitionStatus failed', ['error' => $e->getMessage(), 'app' => Application::APP_ID]);
            return new JSONResponse(['error' => 'Could not transition status'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end transitionStatus()

    /**
     * Trigger beschikking generation for a case.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
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

        $body = $this->readJsonBody();

        $outcome    = (string) ($body['outcome'] ?? '');
        $motivation = (string) ($body['motivation'] ?? '');

        if (in_array($outcome, ['verleend', 'geweigerd'], true) === false) {
            return new JSONResponse(['error' => 'outcome must be verleend or geweigerd'], Http::STATUS_BAD_REQUEST);
        }

        $zaak = $this->fetchZaak(zaakId: $caseId);
        if ($zaak === null) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeZaakMutation(zaak: $zaak, user: $user);

        try {
            $result = $this->beschikkingService->generateBeschikking(
                zaakId: $caseId,
                outcome: $outcome,
                motivation: $motivation,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'zaak_not_found') {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['error' => 'Could not generate beschikking'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController: generateBeschikking failed', ['error' => $e->getMessage(), 'app' => Application::APP_ID]);
            return new JSONResponse(['error' => 'Could not generate beschikking'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end generateBeschikking()

    /**
     * Initiate a samenwerkverzoek for a case.
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
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

        $body = $this->readJsonBody();

        $aangezochtBevoegdGezag = (string) ($body['aangezochtBevoegdGezag'] ?? '');
        $rationale = (string) ($body['rationale'] ?? '');

        if ($aangezochtBevoegdGezag === '') {
            return new JSONResponse(['error' => 'aangezochtBevoegdGezag is required'], Http::STATUS_BAD_REQUEST);
        }

        $zaak = $this->fetchZaak(zaakId: $caseId);
        if ($zaak === null) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeZaakMutation(zaak: $zaak, user: $user);

        $bevoegdGezag = (string) ($zaak['bevoegdGezag'] ?? Application::APP_ID);

        try {
            $result = $this->samenwerkService->initiateSamenwerking(
                zaakId: $caseId,
                aangezochtBevoegdGezag: $aangezochtBevoegdGezag,
                rationale: $rationale,
                initiatorBevoegdGezag: $bevoegdGezag,
            );
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'zaak_not_found') {
                return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['error' => 'Could not initiate samenwerking'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController: initiateSamenwerking failed', ['error' => $e->getMessage(), 'app' => Application::APP_ID]);
            return new JSONResponse(['error' => 'Could not initiate samenwerking'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end initiateSamenwerking()

    /**
     * Respond to a samenwerkverzoek (accept or reject with advice).
     *
     * @param string $samenwerkId UUID of the samenwerkverzoek
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
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
        $advies = null;
        if (isset($body['advies']) === true) {
            $advies = (string) $body['advies'];
        }

        try {
            $result = $this->samenwerkService->respondToSamenwerking(
                samenwerkId: $samenwerkId,
                accept: $accept,
                advies: $advies,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'samenwerkverzoek_not_found') {
                return new JSONResponse(['error' => 'Samenwerkverzoek not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['error' => 'Could not respond to samenwerkverzoek'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('DsoController: respondSamenwerking failed', ['error' => $e->getMessage(), 'app' => Application::APP_ID]);
            return new JSONResponse(['error' => 'Could not respond to samenwerkverzoek'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end respondSamenwerking()

    /**
     * Forward a case to another bevoegd gezag (doorsturen).
     *
     * @param string $caseId UUID of the Procest zaak
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
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

        $body = $this->readJsonBody();
        $doelBevoegdGezag = (string) ($body['doelBevoegdGezag'] ?? '');
        $reden            = (string) ($body['reden'] ?? '');

        if ($doelBevoegdGezag === '') {
            return new JSONResponse(['error' => 'doelBevoegdGezag is required'], Http::STATUS_BAD_REQUEST);
        }

        $zaak = $this->fetchZaak(zaakId: $caseId);
        if ($zaak === null) {
            return new JSONResponse(['error' => 'Case not found'], Http::STATUS_NOT_FOUND);
        }

        $this->authorizeZaakMutation(zaak: $zaak, user: $user);

        // Dispatch doorstuur event for OpenConnector.
        $event = new GenericEvent(
            subject: 'vergunning_doorgestuurd',
            arguments: [
                'zaakId'                => $caseId,
                'doelBevoegdGezag'      => $doelBevoegdGezag,
                'reden'                 => $reden,
                'userId'                => $user->getUID(),
                'vergunningaanvraagRef' => (string) ($zaak['vergunningaanvraagRef'] ?? ''),
            ],
        );
        $this->dispatcher->dispatch(
            eventName: 'OCA\Procest\Event\VergunningDoorgestuurdEvent',
            event: $event,
        );

        $this->logger->info(
            'DsoController: doorsturen dispatched',
            ['app' => Application::APP_ID, 'zaakId' => $caseId, 'doel' => $doelBevoegdGezag],
        );

        return new JSONResponse(
                [
                    'zaakId'           => $caseId,
                    'doelBevoegdGezag' => $doelBevoegdGezag,
                    'reden'            => $reden,
                    'status'           => 'dispatched',
                ]
                );
    }//end doorsturen()

    /**
     * Fetch a zaak object from OpenRegister.
     *
     * @param string $zaakId UUID of the zaak
     *
     * @return array<string, mixed>|null
     */
    private function fetchZaak(string $zaakId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if (empty($register) === true || empty($caseSchema) === true) {
            return null;
        }

        try {
            $zaak = $objectService->findObject(
                register: $register,
                schema: $caseSchema,
                id: $zaakId,
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($zaak === null) {
            return null;
        }

        if (is_array($zaak) === true) {
            return $zaak;
        }

        if (is_object($zaak) === true && method_exists($zaak, 'jsonSerialize') === true) {
            $serialized = $zaak->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end fetchZaak()

    /**
     * Authorize that the current user may mutate a zaak.
     *
     * Per ADR-005 Rule 3: checks assignee, group, or admin status.
     * Throws OCSForbiddenException on unauthorized access.
     *
     * @param array<string, mixed> $zaak Zaak object array
     * @param \OCP\IUser           $user The current user
     *
     * @return void
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException When not authorized
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T06
     */
    private function authorizeZaakMutation(array $zaak, \OCP\IUser $user): void
    {
        $uid = $user->getUID();

        if ($this->groupManager->isAdmin(uid: $uid) === true) {
            return;
        }

        $assignee = (string) ($zaak['assignee'] ?? '');
        if ($assignee === $uid) {
            return;
        }

        // Any authenticated user may act on unassigned zaken.
        if ($assignee === '') {
            return;
        }

        throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authorized to mutate this case');
    }//end authorizeZaakMutation()

    /**
     * Decode a JSON request body safely.
     *
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end readJsonBody()
}//end class
