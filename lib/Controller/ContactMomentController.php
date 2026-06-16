<?php

/**
 * Procest Contactmoment Controller.
 *
 * Authenticated KCC-medewerker API for logging contactmomenten, fetching the
 * case-voorblad, executing quick-actions, and answering doorverbindingen. Every
 * method requires an authenticated session (KCC-medewerker level); privileged
 * actions are additionally scoped server-side.
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
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BurgerIdentificationService;
use OCA\Procest\Service\CaseVoorbladService;
use OCA\Procest\Service\ContactMomentService;
use OCA\Procest\Service\DoorverbindingService;
use OCA\Procest\Service\QuickActionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * REST API for KCC contactmomenten and quick-actions.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
 */
class ContactMomentController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName              The app name.
     * @param IRequest                    $request              The request.
     * @param ContactMomentService        $contactMomentService The contactmoment service.
     * @param CaseVoorbladService         $caseVoorbladService  The case-voorblad service.
     * @param QuickActionService          $quickActionService   The quick-action service.
     * @param DoorverbindingService       $transferService      The doorverbinding service.
     * @param BurgerIdentificationService $burgerService        The burger identification service.
     * @param IUserSession                $userSession          The user session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ContactMomentService $contactMomentService,
        private readonly CaseVoorbladService $caseVoorbladService,
        private readonly QuickActionService $quickActionService,
        private readonly DoorverbindingService $transferService,
        private readonly BurgerIdentificationService $burgerService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Create a contactmoment and return the case-voorblad for the burger.
     *
     * @return JSONResponse The created contactmoment plus case-voorblad.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = [
            'kanaal'              => (string) $this->request->getParam('kanaal', ''),
            'richting'            => (string) $this->request->getParam('richting', 'inkomend'),
            'bellerIdentificatie' => (string) $this->request->getParam('bellerIdentificatie', ''),
            'aard'                => (string) $this->request->getParam('aard', 'informatieverzoek'),
            'samenvatting'        => (string) $this->request->getParam('samenvatting', ''),
            'kccMedewerkerId'     => $user->getUID(),
            'transcriptie'        => (string) $this->request->getParam('transcriptie', ''),
        ];

        // Auto-resolve a burger from the caller identifier when none supplied.
        $burgerId = (string) $this->request->getParam('geidentificeerdeBurgerId', '');
        $method   = (string) $this->request->getParam('identificatieMethode', 'niet_geidentificeerd');
        if ($burgerId === '' && $data['bellerIdentificatie'] !== '') {
            $resolved = $this->burgerService->lookupByIdentifier($data['bellerIdentificatie']);
            if ($resolved !== '') {
                $burgerId = $resolved;
                $method   = 'identificatievragen';
            }
        }

        $identifiedBurgerId = null;
        if ($burgerId !== '') {
            $identifiedBurgerId = $burgerId;
        }

        $data['geidentificeerdeBurgerId'] = $identifiedBurgerId;
        $data['identificatieMethode']     = $method;

        try {
            $contactmoment = $this->contactMomentService->createContactMoment($data);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $voorblad = null;
        if ($burgerId !== '') {
            $voorblad = $this->caseVoorbladService->getCaseVoorblad($burgerId);
        }

        return new JSONResponse(['contactmoment' => $contactmoment, 'voorblad' => $voorblad]);
    }//end create()

    /**
     * List contactmomenten for an identified burger.
     *
     * @param string $burgerId The burger reference.
     * @param int    $limit    The maximum number of records.
     *
     * @return JSONResponse The contactmoment list.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function index(string $burgerId='', int $limit=50): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($burgerId === '') {
            return new JSONResponse(['error' => 'burgerId is required'], Http::STATUS_BAD_REQUEST);
        }

        $records = $this->contactMomentService->listForBurger($burgerId, $limit);
        return new JSONResponse(['contactmomenten' => $records]);
    }//end index()

    /**
     * Fetch the case-voorblad for a burger.
     *
     * @param string $burgerId The burger reference.
     *
     * @return JSONResponse The case-voorblad.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function voorblad(string $burgerId=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($burgerId === '') {
            return new JSONResponse(['error' => 'burgerId is required'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($this->caseVoorbladService->getCaseVoorblad($burgerId));
    }//end voorblad()

    /**
     * Execute the "Status terugkoppelen" quick-action.
     *
     * @return JSONResponse The draft text, or the recorded activity when confirmed.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function statusGeven(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId  = (string) $this->request->getParam('caseId', '');
        $confirm = (bool) $this->request->getParam('confirm', false);

        try {
            $result = $this->quickActionService->executeStatusTerugkoppelen($caseId);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($confirm === true) {
            $this->contactMomentService->recordActivity(
                $caseId,
                '',
                'status_given',
                $user->getUID(),
                $result['draftText'],
            );
        }

        return new JSONResponse($result);
    }//end statusGeven()

    /**
     * Execute the "Nieuwe zaak" quick-action.
     *
     * @return JSONResponse The new case id.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function nieuweZaak(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $zaaktype = (string) $this->request->getParam('zaaktype', '');
        $burgerId = (string) $this->request->getParam('burgerId', '');
        $details  = (array) $this->request->getParam('details', []);

        try {
            $result = $this->quickActionService->executeNieuweZaak($zaaktype, $burgerId, $details);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result);
    }//end nieuweZaak()

    /**
     * Execute the "Klacht registreren" quick-action.
     *
     * @return JSONResponse The klacht case id and deadline.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function klachtRegistreren(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $caseId       = (string) $this->request->getParam('caseId', '');
        $samenvatting = (string) $this->request->getParam('samenvatting', '');
        $burgerId     = (string) $this->request->getParam('burgerId', '');

        try {
            $result = $this->quickActionService->executeKlachtRegistreren($caseId, $samenvatting, $burgerId);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result);
    }//end klachtRegistreren()

    /**
     * Execute the "Doorverbinden" quick-action (initiate warm transfer).
     *
     * @return JSONResponse The doorverbinding id and status.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function doorverbinden(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $data = [
            'contactmomentId'      => (string) $this->request->getParam('contactmomentId', ''),
            'vanMedewerkerId'      => $user->getUID(),
            'naarMedewerkerId'     => $this->request->getParam('naarMedewerkerId', null),
            'naarWachtrij'         => $this->request->getParam('naarWachtrij', null),
            'doorverbindingsReden' => (string) $this->request->getParam('reden', ''),
            'contextSnapshot'      => (string) $this->request->getParam('contextSnapshot', '{}'),
        ];

        try {
            $result = $this->transferService->initiateWarmTransfer($data);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse($result);
    }//end doorverbinden()

    /**
     * Accept a doorverbinding (by the receiving specialist).
     *
     * @param string $id The doorverbinding UUID.
     *
     * @return JSONResponse The updated doorverbinding.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function acceptDoorverbinding(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->transferService->acceptTransfer($id, $user->getUID()));
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end acceptDoorverbinding()

    /**
     * Reject a doorverbinding with a reason.
     *
     * @param string $id The doorverbinding UUID.
     *
     * @return JSONResponse The updated doorverbinding.
     *
     * @NoAdminRequired

     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T11
     */
    public function rejectDoorverbinding(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $reason = (string) $this->request->getParam('reason', '');

        try {
            return new JSONResponse($this->transferService->rejectTransfer($id, $reason, $user->getUID()));
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end rejectDoorverbinding()
}//end class
