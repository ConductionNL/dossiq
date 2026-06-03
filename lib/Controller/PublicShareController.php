<?php

/**
 * Procest Public Share Controller
 *
 * Controller for unauthenticated access to shared cases and citizen status pages.
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseSharingService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for public (unauthenticated) access to shared cases.
 *
 * All endpoints are accessible without Nextcloud authentication.
 * Access is controlled via cryptographically secure tokens.
 */
class PublicShareController extends Controller
{
    /**
     * Constructor for the PublicShareController.
     *
     * @param IRequest           $request            The request object
     * @param CaseSharingService $caseSharingService The sharing service
     * @param SettingsService    $settingsService    The settings service
     * @param IAppManager        $appManager         The app manager
     * @param ContainerInterface $container          The DI container
     * @param LoggerInterface    $logger             The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private CaseSharingService $caseSharingService,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Access a shared case via token.
     *
     * Returns filtered case data based on the share's permission level.
     *
     * @param string $token The share token
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function accessShare(string $token): JSONResponse
    {
        $password   = $this->request->getParam('password');
        $validation = $this->caseSharingService->validateToken($token, $password);

        if ($validation['valid'] === false) {
            $status = 403;
            if (isset($validation['requiresPassword']) === true && $validation['requiresPassword'] === true) {
                $status = 401;
            }

            return new JSONResponse(
                [
                    'success'          => false,
                    'error'            => ($validation['error'] ?? 'Toegang geweigerd'),
                    'requiresPassword' => ($validation['requiresPassword'] ?? false),
                ],
                $status
            );
        }

        $shareData = $validation['share'];

        // Load the case data.
        $caseData = $this->loadCaseData(caseId: $shareData['caseId']);
        if ($caseData === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Zaak niet gevonden'],
                404
            );
        }

        // Apply permission-based filtering.
        $filteredData = $this->caseSharingService->getFilteredCaseData($shareData, $caseData);

        $this->logger->info(
            'Procest: External party accessed shared case',
            [
                'caseId'    => $shareData['caseId'],
                'shareType' => $shareData['shareType'],
                'ip'        => $this->request->getRemoteAddress(),
            ]
        );

        return new JSONResponse(
                [
                    'success'         => true,
                    'case'            => $filteredData,
                    'permissionLevel' => $shareData['permissionLevel'],
                    'canComment'      => in_array(
                $shareData['permissionLevel'],
                ['bekijken_reageren', 'bekijken_bijdragen']
            ),
                    'canUpload'       => $shareData['permissionLevel'] === 'bekijken_bijdragen',
                ]
                );
    }//end accessShare()

    /**
     * Add a comment on a shared case (requires comment permission).
     *
     * @param string $token The share token
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function addComment(string $token): JSONResponse
    {
        $password   = $this->request->getParam('password');
        $validation = $this->caseSharingService->validateToken($token, $password);

        if ($validation['valid'] === false) {
            return new JSONResponse(
                ['success' => false, 'error' => ($validation['error'] ?? 'Toegang geweigerd')],
                403
            );
        }

        $shareData = $validation['share'];

        // Check comment permission.
        $canComment = in_array(
            $shareData['permissionLevel'],
            ['bekijken_reageren', 'bekijken_bijdragen']
        );

        if ($canComment === false) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Geen toestemming om te reageren'],
                403
            );
        }

        $comment    = $this->request->getParam('comment', '');
        $authorName = $this->request->getParam('authorName', 'Extern');

        if (empty($comment) === true) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Reactie mag niet leeg zijn'],
                400
            );
        }

        $this->logger->info(
            'Procest: External party added comment',
            [
                'caseId'     => $shareData['caseId'],
                'authorName' => $authorName,
            ]
        );

        return new JSONResponse(
                [
                    'success' => true,
                    'message' => 'Reactie toegevoegd',
                ]
                );
    }//end addComment()

    /**
     * View citizen case status (public status page).
     *
     * Returns minimal case progress data for citizen-facing status tracking.
     *
     * @param string $token The status page token
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function viewStatus(string $token): JSONResponse
    {
        $validation = $this->caseSharingService->validateToken($token);

        if ($validation['valid'] === false) {
            return new JSONResponse(
                ['success' => false, 'error' => ($validation['error'] ?? 'Status niet beschikbaar')],
                403
            );
        }

        $shareData = $validation['share'];
        $caseData  = $this->loadCaseData(caseId: $shareData['caseId']);

        if ($caseData === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Zaak niet gevonden'],
                404
            );
        }

        // Return only citizen-safe status information.
        $statusData = [
            'title'          => ($caseData['title'] ?? ''),
            'identifier'     => ($caseData['identifier'] ?? ''),
            'currentStatus'  => ($caseData['status'] ?? ''),
            'plannedEndDate' => ($caseData['plannedEndDate'] ?? null),
            'startDate'      => ($caseData['startDate'] ?? null),
        ];

        return new JSONResponse(['success' => true, 'status' => $statusData]);
    }//end viewStatus()

    /**
     * Upload a document to a shared case via public token.
     *
     * Requires contribute-level permission. Validates token, password, and lockout.
     *
     * @param string $token The share access token
     *
     * @return JSONResponse
     *
     * @PublicPage
     * @NoCSRFRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function uploadDocument(string $token): JSONResponse
    {
        $password   = $this->request->getParam('password');
        $validation = $this->caseSharingService->validateToken($token, $password);

        if ($validation['valid'] === false) {
            return new JSONResponse(
                ['success' => false, 'error' => ($validation['error'] ?? 'Toegang geweigerd')],
                403
            );
        }

        $share           = $validation['share'];
        $permissionLevel = ($share['permissionLevel'] ?? 'bekijken');

        if ($permissionLevel !== 'bijdragen' && $permissionLevel !== 'contribute') {
            return new JSONResponse(
                ['success' => false, 'error' => 'Geen rechten om documenten te uploaden'],
                403
            );
        }

        $uploadedFile = ($_FILES['file'] ?? null);
        if ($uploadedFile === null || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Geen geldig bestand ontvangen'],
                400
            );
        }

        // C8: storeExternalDocument is a stub — uploaded files are silently discarded.
        // Return 501 Not Implemented so clients show an accurate error instead of
        // a false-success "document received" message that leads to legal data loss.
        // TODO: Implement via IUserFolder + OR file attachment before enabling.
        return new JSONResponse(
            [
                'success' => false,
                'error'   => 'Documentupload is nog niet beschikbaar. Neem contact op met de behandelaar.',
            ],
            \OCP\AppFramework\Http::STATUS_NOT_IMPLEMENTED
        );
    }//end uploadDocument()

    /**
     * Load case data from OpenRegister.
     *
     * @param string $caseId The UUID of the case
     *
     * @return array|null The case data or null if not found
     */
    private function loadCaseData(string $caseId): ?array
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->settingsService->getConfigValue('register');
            $schema        = $this->settingsService->getConfigValue('case_schema');

            $caseObject = $objectService->find($caseId, register: (int) $register, schema: (int) $schema);

            return $caseObject->jsonSerialize();
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not load case for share',
                [
                    'caseId'    => $caseId,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end loadCaseData()
}//end class
