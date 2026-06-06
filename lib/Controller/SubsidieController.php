<?php

/**
 * Procest Subsidie Controller.
 *
 * REST endpoints for the subsidieverlening-keten under `/api/subsidies`. All
 * authenticated endpoints require a logged-in user (`@NoAdminRequired`); the
 * underlying services are IDOR-safe (reads/writes scoped server-side, ids
 * validated). The terugvordering publication path is manager-gated. The
 * subsidieregister export is a public feed (Wet open overheid art. 3.3) that
 * returns only anonymised, already-published data.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Subsidie\BeschikkingService;
use OCA\Procest\Service\Subsidie\SubsidieService;
use OCA\Procest\Service\Subsidie\TussenrapportageService;
use OCA\Procest\Service\Subsidie\VaststellingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing the subsidy lifecycle endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — aggregates the four
 * subsidy lifecycle services it dispatches to.
 */
class SubsidieController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request                 The request.
     * @param SubsidieService         $subsidieService         Core subsidy service.
     * @param BeschikkingService      $beschikkingService      Grant-decision service.
     * @param TussenrapportageService $tussenrapportageService Interim-report service.
     * @param VaststellingService     $vaststellingService     Settlement service.
     * @param IUserSession            $userSession             The user session.
     */
    public function __construct(
        IRequest $request,
        private readonly SubsidieService $subsidieService,
        private readonly BeschikkingService $beschikkingService,
        private readonly TussenrapportageService $tussenrapportageService,
        private readonly VaststellingService $vaststellingService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List subsidieaanvragen with optional filters.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function index(): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $filters = [
            'status'           => $this->request->getParam('status', ''),
            'subsidieregeling' => $this->request->getParam('regeling', ''),
            'behandelaar'      => $this->request->getParam('behandelaar', ''),
        ];

        try {
            $results = $this->subsidieService->listAanvragen($filters);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['results' => $results]);
    }//end index()

    /**
     * Create a subsidieaanvraag.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function create(): JSONResponse
    {
        $userId = $this->requireUser();
        if ($userId === null) {
            return $this->unauthorized();
        }

        $body = $this->bodyParams();
        // The behandelaar is the acting user unless explicitly assigned.
        if (((string) ($body['behandelaar'] ?? '')) === '') {
            $body['behandelaar'] = $userId;
        }

        $termijn = (int) ($body['termijnWeken'] ?? SubsidieService::DEFAULT_AANVRAAG_TERMIJN_WEKEN);
        unset($body['termijnWeken']);

        try {
            $aanvraag = $this->subsidieService->createAanvraag($body, $termijn);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($aanvraag, Http::STATUS_CREATED);
    }//end create()

    /**
     * Transition a subsidieaanvraag to a new status.
     *
     * @param string $id The aanvraag id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function transition(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $toStatus = (string) $this->request->getParam('status', '');

        try {
            $aanvraag = $this->subsidieService->transitionAanvraag($id, $toStatus);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($aanvraag);
    }//end transition()

    /**
     * Draft a beschikking for an aanvraag.
     *
     * @param string $id The aanvraag id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function createBeschikking(string $id): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $body     = $this->bodyParams();
        $sequence = (int) ($body['sequence'] ?? 1);
        unset($body['sequence']);

        try {
            $beschikking = $this->beschikkingService->createDraft($id, $body, $sequence);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($beschikking, Http::STATUS_CREATED);
    }//end createBeschikking()

    /**
     * Publish a beschikking (legal effect; starts the bezwaartermijn).
     *
     * @param string $beschikkingId The beschikking id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function publishBeschikking(string $beschikkingId): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        try {
            $beschikking = $this->beschikkingService->publish($beschikkingId);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($beschikking);
    }//end publishBeschikking()

    /**
     * Sign a beschikking (signer derived from the session).
     *
     * @param string $beschikkingId The beschikking id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function signBeschikking(string $beschikkingId): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        try {
            $beschikking = $this->beschikkingService->sign($beschikkingId);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($beschikking);
    }//end signBeschikking()

    /**
     * Approve an interim report.
     *
     * @param string $reportId The tussenrapportage id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function approveTussenrapportage(string $reportId): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $oordeel = $this->request->getParam('beoordelingsoordeel', null);
        $bedrag  = $this->request->getParam('ingekeurdeBedrag', null);

        $oordeelArg = null;
        if ($oordeel !== null) {
            $oordeelArg = (string) $oordeel;
        }

        $bedragArg = null;
        if ($bedrag !== null) {
            $bedragArg = (float) $bedrag;
        }

        try {
            $report = $this->tussenrapportageService->approveReport(
                reportId: $reportId,
                beoordelingsoordeel: $oordeelArg,
                ingekeurdeBedrag: $bedragArg,
            );
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($report);
    }//end approveTussenrapportage()

    /**
     * Finalise a settlement (auto-triggers terugvordering when overpaid).
     *
     * @param string $vaststellingId The vaststelling id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function finalizeVaststelling(string $vaststellingId): JSONResponse
    {
        if ($this->requireUser() === null) {
            return $this->unauthorized();
        }

        $verleend     = (float) $this->request->getParam('verleendBedrag', 0);
        $werkelijke   = (float) $this->request->getParam('werkelijkeKosten', 0);
        $voorschotten = (float) $this->request->getParam('totaalVoorschotten', 0);

        try {
            $result = $this->vaststellingService->finalize($vaststellingId, $verleend, $werkelijke, $voorschotten);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result);
    }//end finalizeVaststelling()

    /**
     * Resolve the authenticated user id, or null when unauthenticated.
     *
     * @return string|null The user id.
     */
    private function requireUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end requireUser()

    /**
     * Read the JSON / form body parameters, excluding routing params.
     *
     * @return array<string, mixed> The body parameters.
     */
    private function bodyParams(): array
    {
        $params = $this->request->getParams();
        unset($params['id'], $params['beschikkingId'], $params['reportId'], $params['vaststellingId'], $params['_route']);
        return $params;
    }//end bodyParams()

    /**
     * Build a 401 Unauthorized response.
     *
     * @return JSONResponse
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
    }//end unauthorized()
}//end class
