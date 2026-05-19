<?php

/**
 * Procest Consultation Controller
 *
 * REST API controller for inter-departmental consultation management.
 * Authenticated endpoints under /api/consultations, plus a public token-based
 * endpoint for external advisory bodies without Nextcloud accounts.
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
 * @spec openspec/changes/consultation-management/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ConsultationService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for consultation (adviesaanvraag) management.
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-4
 */
class ConsultationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             The app name
     * @param IRequest            $request             The request
     * @param ConsultationService $consultationService The consultation service
     * @param SettingsService     $settingsService     The settings service
     * @param IUserSession        $userSession         The user session
     * @param LoggerInterface     $logger              The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConsultationService $consultationService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List consultations for a parent case.
     *
     * @param string $caseId The parent case UUID
     *
     * @return JSONResponse List of consultations
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function index(string $caseId): JSONResponse
    {
        if ($caseId === '') {
            return new JSONResponse(
                ['error' => 'caseId is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $consultations = $this->consultationService->getConsultationsForCase(caseId: $caseId);
        return new JSONResponse(['results' => $consultations]);
    }//end index()

    /**
     * Get a single consultation by UUID.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse The consultation or 404
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $consultation = $this->consultationService->getConsultation(id: $id);
        if ($consultation === null) {
            return new JSONResponse(
                ['error' => 'Consultation not found'],
                Http::STATUS_NOT_FOUND,
            );
        }

        return new JSONResponse($consultation);
    }//end show()

    /**
     * Create a new consultation linked to a parent case.
     *
     * @return JSONResponse Created consultation or error
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $data   = $this->readJsonBody();
            $result = $this->consultationService->createConsultation(
                data: $data,
                requesterId: $user->getUID(),
            );
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: consultation create failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Could not create consultation'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end create()

    /**
     * Update the status of a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation or error
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function updateStatus(string $id): JSONResponse
    {
        $consultation = $this->consultationService->getConsultation(id: $id);
        if ($consultation === null) {
            return new JSONResponse(
                ['error' => 'Consultation not found'],
                Http::STATUS_NOT_FOUND,
            );
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $data      = $this->readJsonBody();
            $newStatus = (string) ($data['status'] ?? '');
            if ($newStatus === '') {
                return new JSONResponse(
                    ['error' => 'status is required'],
                    Http::STATUS_BAD_REQUEST,
                );
            }

            $result = $this->consultationService->transitionStatus(
                consultationId: $id,
                newStatus: $newStatus,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: consultation status update failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Could not update consultation status'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end updateStatus()

    /**
     * Submit an advice response to a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Created advice response or error
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function submitResponse(string $id): JSONResponse
    {
        $consultation = $this->consultationService->getConsultation(id: $id);
        if ($consultation === null) {
            return new JSONResponse(
                ['error' => 'Consultation not found'],
                Http::STATUS_NOT_FOUND,
            );
        }

        try {
            $data   = $this->readJsonBody();
            $result = $this->consultationService->submitResponse(
                consultationId: $id,
                response: $data,
            );
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: consultation submitResponse failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Could not submit response'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end submitResponse()

    /**
     * Claim a consultation as the individual handler.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation or error
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function claimConsultation(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $consultation = $this->consultationService->getConsultation(id: $id);
        if ($consultation === null) {
            return new JSONResponse(
                ['error' => 'Consultation not found'],
                Http::STATUS_NOT_FOUND,
            );
        }

        try {
            $result = $this->consultationService->claimConsultation(
                consultationId: $id,
                userId: $user->getUID(),
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        }
    }//end claimConsultation()

    /**
     * Request a deadline extension for a consultation.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation or error
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function requestExtension(string $id): JSONResponse
    {
        $consultation = $this->consultationService->getConsultation(id: $id);
        if ($consultation === null) {
            return new JSONResponse(
                ['error' => 'Consultation not found'],
                Http::STATUS_NOT_FOUND,
            );
        }

        try {
            $data          = $this->readJsonBody();
            $justification = (string) ($data['justification'] ?? '');
            $result        = $this->consultationService->requestExtension(
                consultationId: $id,
                justification: $justification,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        }
    }//end requestExtension()

    /**
     * Get overdue consultations across the system.
     *
     * @return JSONResponse List of overdue consultations
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function overdue(): JSONResponse
    {
        $overdue = $this->consultationService->getOverdueConsultations();
        return new JSONResponse(['results' => $overdue]);
    }//end overdue()

    /**
     * Get consultations assigned to a department (advisory body).
     *
     * @param string $departmentId The advisory body UUID
     *
     * @return JSONResponse List of consultations for the department
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function byDepartment(string $departmentId): JSONResponse
    {
        $consultations = $this->consultationService->getConsultationsByDepartment(
            advisoryBodyId: $departmentId,
        );
        return new JSONResponse(['results' => $consultations]);
    }//end byDepartment()

    /**
     * Get mandatory consultations blocking case progression.
     *
     * @param string $caseId The parent case UUID
     *
     * @return JSONResponse Blocking consultations and blocked flag
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function blockingForCase(string $caseId): JSONResponse
    {
        $blocking = $this->consultationService->getBlockingConsultations(zaakId: $caseId);
        return new JSONResponse(
                [
                    'results' => $blocking,
                    'blocked' => count($blocking) > 0,
                ]
                );
    }//end blockingForCase()

    /**
     * Public endpoint — external advisory body submits response via secure token.
     *
     * Access is audited per BIO requirements. Token prefix logged (never full token).
     *
     * @param string $token The secure response token (256-bit hex)
     *
     * @return JSONResponse Success or error
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function publicRespond(string $token): JSONResponse
    {
        $this->logger->info(
            'Procest: external consultation response attempt',
            [
                'app'          => Application::APP_ID,
                'token_prefix' => substr(string: $token, offset: 0, length: 8),
                'timestamp'    => date(format: 'c'),
            ],
        );

        if (strlen($token) < 32) {
            return new JSONResponse(
                ['error' => 'Invalid token'],
                Http::STATUS_FORBIDDEN,
            );
        }

        try {
            $data   = $this->readJsonBody();
            $result = $this->consultationService->processExternalResponse(
                token: $token,
                response: $data,
            );

            if ($result === null) {
                $this->logger->warning(
                    'Procest: external consultation response — token not found or expired',
                    [
                        'app'          => Application::APP_ID,
                        'token_prefix' => substr(string: $token, offset: 0, length: 8),
                    ],
                );
                return new JSONResponse(
                    ['error' => 'Token not found or expired'],
                    Http::STATUS_FORBIDDEN,
                );
            }

            $this->logger->info(
                'Procest: external consultation response accepted',
                [
                    'app'          => Application::APP_ID,
                    'token_prefix' => substr(string: $token, offset: 0, length: 8),
                    'consultation' => $result['id'] ?? '',
                ],
            );

            return new JSONResponse(['status' => 'accepted', 'consultation' => $result]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: external consultation response failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            return new JSONResponse(
                ['error' => 'Could not process response'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end publicRespond()

    /**
     * Decode a JSON request body safely.
     *
     * @return array<string, mixed> Decoded payload or empty array
     */
    private function readJsonBody(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode(json: $content, associative: true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end readJsonBody()
}//end class
