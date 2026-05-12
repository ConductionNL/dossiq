<?php

/**
 * Procest Consultation Controller
 *
 * REST API for inter-departmental consultation management.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ConsultationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for consultation (adviesaanvraag) management.
 */
class ConsultationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             The app name
     * @param IRequest            $request             The request
     * @param ConsultationService $consultationService The consultation service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ConsultationService $consultationService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List consultations for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse List of consultations
     *
     * @NoAdminRequired
     */
    public function index(string $caseId): JSONResponse
    {
        $consultations = $this->consultationService->getConsultationsForCase($caseId);
        return new JSONResponse(['results' => $consultations]);
    }//end index()

    /**
     * Create a new consultation.
     *
     * @return JSONResponse Created consultation
     *
     * @NoAdminRequired
     */
    public function create(): JSONResponse
    {
        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) === true) {
                $data = $decoded;
            } else {
                $data = [];
            }

            $result = $this->consultationService->createConsultation($data);
            return new JSONResponse($result, 201);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end create()

    /**
     * Update consultation status.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation
     *
     * @NoAdminRequired
     */
    public function updateStatus(string $id): JSONResponse
    {
        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) === true) {
                $data = $decoded;
            } else {
                $data = [];
            }

            $status = $data['status'] ?? '';
            $result = $this->consultationService->updateStatus($id, $status);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end updateStatus()

    /**
     * Submit advice response.
     *
     * @param string $id The consultation UUID
     *
     * @return JSONResponse Updated consultation
     *
     * @NoAdminRequired
     */
    public function submitResponse(string $id): JSONResponse
    {
        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) === true) {
                $data = $decoded;
            } else {
                $data = [];
            }

            $result = $this->consultationService->submitResponse($id, $data);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end submitResponse()

    /**
     * Get overdue consultations.
     *
     * @return JSONResponse List of overdue consultations
     *
     * @NoAdminRequired
     */
    public function overdue(): JSONResponse
    {
        $overdue = $this->consultationService->getOverdueConsultations();
        return new JSONResponse(['results' => $overdue]);
    }//end overdue()
}//end class
