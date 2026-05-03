<?php

/**
 * Procest Template Controller
 *
 * REST API for managing zaaktype templates.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

use OCA\Procest\Service\TemplateLibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for zaaktype template management.
 */
class TemplateController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName         The app name
     * @param IRequest               $request         The request
     * @param TemplateLibraryService $templateService The template service
     * @param LoggerInterface        $logger          The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TemplateLibraryService $templateService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all available templates.
     *
     * @return JSONResponse List of templates
     *
     * @NoAdminRequired
     */
    public function index(): JSONResponse
    {
        $templates = $this->templateService->listTemplates();
        return new JSONResponse(['results' => $templates]);
    }//end index()

    /**
     * Get a single template by ID.
     *
     * @param string $id The template ID
     *
     * @return JSONResponse The template data or 404
     *
     * @NoAdminRequired
     */
    public function show(string $id): JSONResponse
    {
        $template = $this->templateService->loadTemplate($id);
        if ($template === null) {
            return new JSONResponse(['error' => 'Template not found'], 404);
        }

        return new JSONResponse($template);
    }//end show()

    /**
     * Activate a template (create all objects from it).
     *
     * @param string $id The template ID
     *
     * @return JSONResponse Result with created object IDs
     *
     * @NoAdminRequired
     */
    public function activate(string $id): JSONResponse
    {
        try {
            $result = $this->templateService->activateTemplate($id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                400,
            );
        }
    }//end activate()
}//end class
