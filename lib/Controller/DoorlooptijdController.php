<?php

/**
 * Procest Doorlooptijd Controller
 *
 * API controller for doorlooptijd (processing time) analytics endpoints.
 * Provides metrics for the doorlooptijd dashboard including SLA compliance,
 * processing time distribution, trends, and at-risk case identification.
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
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-02
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\DoorlooptijdService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for doorlooptijd (processing time) analytics API.
 *
 * @psalm-suppress UnusedClass
 */
class DoorlooptijdController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                $appName           The application name
     * @param IRequest              $request           The request object
     * @param DoorlooptijdService   $doorlooptijdService Doorlooptijd service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DoorlooptijdService $doorlooptijdService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()


    /**
     * Get doorlooptijd metrics for the analytics dashboard.
     *
     * GET /api/procest/doorlooptijd/metrics
     *
     * Query parameters:
     * - from (required): ISO 8601 date (start of range)
     * - to (required): ISO 8601 date (end of range)
     * - caseType (optional): Case type ID filter
     *
     * Returns metrics object with slaCompliance, distribution, monthlyTrend,
     * atRiskCases, and performanceTable.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-DL-02
     */
    public function getMetrics(): JSONResponse
    {
        try {
            $from = $this->request->getParam('from');
            $to = $this->request->getParam('to');
            $caseTypeId = $this->request->getParam('caseType');

            // Validate required parameters
            if (empty($from) || empty($to)) {
                return new JSONResponse(
                    ['message' => 'Missing required parameters: from and to'],
                    400
                );
            }

            // Validate date format
            if (!\DateTime::createFromFormat('Y-m-d', $from) || !\DateTime::createFromFormat('Y-m-d', $to)) {
                return new JSONResponse(
                    ['message' => 'Invalid date format. Use YYYY-MM-DD'],
                    400
                );
            }

            $metrics = $this->doorlooptijdService->getMetrics($from, $to, $caseTypeId);

            return new JSONResponse($metrics);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['message' => 'Error retrieving metrics: ' . $e->getMessage()],
                500
            );
        }
    }//end getMetrics()
}//end class
