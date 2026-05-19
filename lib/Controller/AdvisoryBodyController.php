<?php

/**
 * Procest Advisory Body Controller
 *
 * REST API for the advisory body registry (departments and external organizations
 * that can receive consultation requests).
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

use OCA\Procest\Service\AdvisoryBodyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for the advisory body registry.
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-4
 */
class AdvisoryBodyController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             The app name
     * @param IRequest            $request             The request
     * @param AdvisoryBodyService $advisoryBodyService The advisory body service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AdvisoryBodyService $advisoryBodyService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all active advisory bodies.
     *
     * @return JSONResponse All advisory bodies
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $bodies = $this->advisoryBodyService->listAll();
        return new JSONResponse(['results' => $bodies]);
    }//end index()

    /**
     * Search advisory bodies by specialization or name.
     *
     * Bodies with matching specialization tags are ranked first.
     *
     * @param string $query The search query
     *
     * @return JSONResponse Ranked search results
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function search(string $query): JSONResponse
    {
        $results = $this->advisoryBodyService->searchBySpecialization(query: $query);
        return new JSONResponse(['results' => $results]);
    }//end search()
}//end class
