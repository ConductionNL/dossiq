<?php

/**
 * Procest Advice Controller.
 *
 * REST API for managing advice requests (adviesAanvraag) on cases.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

use OCA\Procest\Service\AdviceService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice request management on cases.
 */
class AdviceController extends Controller
{

    /**
     * Constructor.
     *
     * @param string          $appName         The app name
     * @param IRequest        $request         The HTTP request
     * @param AdviceService   $adviceService   The advice service
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AdviceService $adviceService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List advice requests for a case.
     *
     * @param string $case The case UUID (query param)
     *
     * @return JSONResponse List of advice records
     *
     * @NoAdminRequired
     */
    public function index(string $case = ''): JSONResponse
    {
        if ($case === '') {
            return new JSONResponse(
                ['error' => 'case parameter is required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $advice = $this->adviceService->getAdviceForCase($case);
            return new JSONResponse(['results' => $advice]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice index failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not list advice requests'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end index()

    /**
     * Create a new advice request.
     *
     * @return JSONResponse Created record
     *
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        $data = $this->readJsonBody();

        $caseId = (string) ($data['case'] ?? '');

        try {
            $advice = $this->adviceService->createAdvice($caseId, $data);
            return new JSONResponse($advice, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice create failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not create advice request'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end create()

    /**
     * Show a single advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Advice record
     *
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(
                ['error' => 'OpenRegister is not available'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            return new JSONResponse(
                ['error' => 'Advice schema is not configured'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $advice = $objectService->findObject($register, $schema, $id);
            return new JSONResponse($advice);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice show failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not load advice request'],
                Http::STATUS_NOT_FOUND,
            );
        }
    }//end show()

    /**
     * Update / mark received an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Updated record
     *
     * @NoAdminRequired
     */
    public function update(string $id): JSONResponse
    {
        $data   = $this->readJsonBody();
        $fileId = (string) ($data['adviesDocument'] ?? ($data['fileId'] ?? ''));

        try {
            $advice = $this->adviceService->receiveAdvice($id, $fileId);
            return new JSONResponse($advice);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice update failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not update advice request'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end update()

    /**
     * Delete an advice request.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Empty success or error
     *
     * @NoAdminRequired
     */
    public function destroy(string $id): JSONResponse
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return new JSONResponse(
                ['error' => 'OpenRegister is not available'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advies_aanvraag_schema');

        if (empty($register) === true || empty($schema) === true) {
            return new JSONResponse(
                ['error' => 'Advice schema is not configured'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        try {
            $objectService->deleteObject($register, $schema, $id);
            return new JSONResponse(['status' => 'deleted']);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice destroy failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not delete advice request'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end destroy()

    /**
     * Send a manual reminder to the adviseur.
     *
     * @param string $id The advice UUID
     *
     * @return JSONResponse Success response
     *
     * @NoAdminRequired
     */
    public function remind(string $id): JSONResponse
    {
        try {
            $this->adviceService->sendReminder($id);
            return new JSONResponse(['status' => 'reminded']);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: advice remind failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Could not send reminder'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }//end remind()

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

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end readJsonBody()
}//end class
