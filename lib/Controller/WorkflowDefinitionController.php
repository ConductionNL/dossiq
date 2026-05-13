<?php

/**
 * Procest Workflow Definition Controller
 *
 * Action endpoints for workflowTemplate lifecycle transitions. Pure CRUD
 * is handled by the manifest renderer + OpenRegister auto-routing under
 * /api/objects/<register>/<schema>; this controller only owns the
 * lifecycle actions (publish, deprecate, clone) and the read-only
 * consumer-contract endpoints used by status-transition-engine.
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
use OCA\Procest\Service\WorkflowDefinitionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lifecycle-only controller for workflow definitions.
 */
class WorkflowDefinitionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request The request object
     * @param WorkflowDefinitionService $service The workflow definition service
     */
    public function __construct(
        IRequest $request,
        private WorkflowDefinitionService $service,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish a draft definition.
     *
     * Atomically: flips target to published+active, deprecates the
     * previously active version for the same caseType, and pins
     * caseType.workflowDefinition to the new active id.
     *
     * @param string $id The workflow definition UUID
     *
     * @return JSONResponse
     */
    public function publish(string $id): JSONResponse
    {
        $result = $this->service->publish($id);
        if ($result === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not publish workflow definition'],
                400,
            );
        }

        return new JSONResponse(['success' => true, 'definition' => $result]);
    }//end publish()

    /**
     * Deprecate a published definition.
     *
     * Refuses when this is the last published version for its caseType
     * and open cases still depend on it.
     *
     * @param string $id The workflow definition UUID
     *
     * @return JSONResponse
     */
    public function deprecate(string $id): JSONResponse
    {
        $result = $this->service->deprecate($id);
        if ($result === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not deprecate workflow definition'],
                400,
            );
        }

        return new JSONResponse(['success' => true, 'definition' => $result]);
    }//end deprecate()

    /**
     * Clone an existing definition into a new draft.
     *
     * @param string $id The source definition UUID
     *
     * @return JSONResponse
     */
    public function cloneDefinition(string $id): JSONResponse
    {
        $result = $this->service->cloneDefinition($id);
        if ($result === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not clone workflow definition'],
                400,
            );
        }

        return new JSONResponse(['success' => true, 'definition' => $result]);
    }//end cloneDefinition()

    /**
     * Read-only consumer endpoint — returns the active definition for a
     * caseType. Used by other apps when they need to consult the
     * authoritative workflow for case orchestration.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return JSONResponse
     */
    public function active(string $caseTypeId): JSONResponse
    {
        $definition = $this->service->getActiveDefinitionFor($caseTypeId);
        if ($definition === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'No active workflow definition'],
                404,
            );
        }

        return new JSONResponse(['success' => true, 'definition' => $definition]);
    }//end active()

    /**
     * Read-only consumer endpoint — returns the definition pinned to a
     * specific case via case.workflowTemplate + case.workflowVersion.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse
     */
    public function forCase(string $caseId): JSONResponse
    {
        $definition = $this->service->getDefinitionForCase($caseId);
        if ($definition === null) {
            return new JSONResponse(
                ['success' => false, 'error' => 'No workflow definition for case'],
                404,
            );
        }

        return new JSONResponse(['success' => true, 'definition' => $definition]);
    }//end forCase()
}//end class
