<?php

/**
 * Procest Case Sharing Controller
 *
 * Controller for managing case shares and partner organizations.
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\CaseTransferService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for managing case shares, partner organizations, and transfers.
 */
class CaseSharingController extends Controller
{
    /**
     * Constructor for the CaseSharingController.
     *
     * @param IRequest            $request             The request object
     * @param CaseSharingService  $caseSharingService  The sharing service
     * @param CaseTransferService $caseTransferService The transfer service
     * @param SettingsService     $settingsService     The settings service
     * @param IAppManager         $appManager          The app manager
     * @param ContainerInterface  $container           The DI container
     * @param IUserSession        $userSession         The user session
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — all params needed for DI
     */
    public function __construct(
        IRequest $request,
        private CaseSharingService $caseSharingService,
        private CaseTransferService $caseTransferService,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all active shares for a case.
     *
     * @NoAdminRequired
     *
     * @param string $caseId The UUID of the case
     *
     * @return JSONResponse
     */
    public function listShares(string $caseId): JSONResponse
    {
        $shares = $this->caseSharingService->getSharesByCase($caseId);
        return new JSONResponse(['success' => true, 'shares' => array_values($shares)]);
    }//end listShares()

    /**
     * Create a new case share (token or partner).
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function createShare(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], 401);
        }

        $caseId          = $this->request->getParam('caseId');
        $shareType       = $this->request->getParam('shareType', 'token');
        $permissionLevel = $this->request->getParam('permissionLevel', 'bekijken');
        $label           = $this->request->getParam('label', '');

        if (empty($caseId) === true) {
            return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
        }

        if ($shareType === 'partner') {
            $partnerId = $this->request->getParam('partnerId');
            if (empty($partnerId) === true) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'partnerId is required for partner shares'],
                    400
                );
            }

            $share = $this->caseSharingService->createPartnerShare(
                $caseId,
                $partnerId,
                $permissionLevel,
                $user->getUID(),
            );
        } else {
            $expiresAt       = $this->request->getParam('expiresAt');
            $password        = $this->request->getParam('password');
            $fieldExclusions = json_decode($this->request->getParam('fieldExclusions', '[]'), true);
            if (is_array($fieldExclusions) === false) {
                $fieldExclusions = [];
            }

            $share = $this->caseSharingService->createTokenShare(
                $caseId,
                $permissionLevel,
                $label,
                $user->getUID(),
                $expiresAt,
                $password,
                $fieldExclusions,
            );
        }//end if

        return new JSONResponse(['success' => true, 'share' => $share]);
    }//end createShare()

    /**
     * Revoke a case share.
     *
     * @NoAdminRequired
     *
     * @param string $shareId The UUID of the share to revoke
     *
     * @return JSONResponse
     */
    public function revokeShare(string $shareId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], 401);
        }

        $share = $this->caseSharingService->revokeShare($shareId, $user->getUID());
        return new JSONResponse(['success' => true, 'share' => $share]);
    }//end revokeShare()

    /**
     * Initiate a case transfer to another organization.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function initiateTransfer(): JSONResponse
    {
        $caseId = $this->request->getParam('caseId');
        $sourceOrganization = $this->request->getParam('sourceOrganization', '');
        $targetOrganization = $this->request->getParam('targetOrganization');
        $reason        = $this->request->getParam('reason', '');
        $requestedDate = $this->request->getParam('requestedDate', date('Y-m-d'));

        if (empty($caseId) === true || empty($targetOrganization) === true) {
            return new JSONResponse(
                ['success' => false, 'error' => 'caseId and targetOrganization are required'],
                400
            );
        }

        $transfer = $this->caseTransferService->initiateTransfer(
            $caseId,
            $sourceOrganization,
            $targetOrganization,
            $reason,
            $requestedDate,
        );

        return new JSONResponse(['success' => true, 'transfer' => $transfer]);
    }//end initiateTransfer()

    /**
     * Handle a transfer request (accept or reject).
     *
     * @NoAdminRequired
     *
     * @param string $transferId The UUID of the transfer request
     *
     * @return JSONResponse
     */
    public function handleTransfer(string $transferId): JSONResponse
    {
        $action = $this->request->getParam('action');

        if ($action === 'accept') {
            $result = $this->caseTransferService->acceptTransfer($transferId);
        } else if ($action === 'reject') {
            $reason = $this->request->getParam('reason', '');
            $result = $this->caseTransferService->rejectTransfer($transferId, $reason);
        } else {
            return new JSONResponse(
                ['success' => false, 'error' => 'Action must be accept or reject'],
                400
            );
        }

        return new JSONResponse(['success' => true, 'transfer' => $result]);
    }//end handleTransfer()
}//end class
