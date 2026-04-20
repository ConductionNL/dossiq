<?php

/**
 * Procest Health Controller
 *
 * Exposes health check endpoint for container orchestration and monitoring.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoints.
 *
 * @psalm-suppress UnusedClass
 */
class HealthController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Health check endpoint.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Health status
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Check database connectivity.
        $checks['database'] = $this->checkDatabase();
        if ($checks['database'] !== 'ok') {
            $status = 'error';
        }

        // Check OpenRegister dependency (hard dependency).
        $checks['openregister'] = $this->checkOpenRegister();
        if ($checks['openregister'] !== 'ok') {
            $status = 'error';
        }

        // Check filesystem.
        $checks['filesystem'] = $this->checkFilesystem();
        if ($checks['filesystem'] !== 'ok' && $status !== 'error') {
            $status = 'degraded';
        }

        if ($status === 'ok') {
            $httpStatus = Http::STATUS_OK;
        } else {
            $httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
        }

        return new JSONResponse(
            [
                'status'  => $status,
                'version' => $this->getAppVersion(),
                'checks'  => $checks,
            ],
            $httpStatus
        );
    }//end index()

    /**
     * Check database connectivity.
     *
     * @return string 'ok' or error message
     */
    private function checkDatabase(): string
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();

            return 'ok';
        } catch (\Exception $e) {
            $this->logger->error('[HealthController] Database check failed', ['error' => $e->getMessage()]);
            return 'failed: '.$e->getMessage();
        }
    }//end checkDatabase()

    /**
     * Check OpenRegister app availability.
     *
     * OpenRegister is a hard dependency for Procest. If it is not enabled,
     * the overall health status MUST be "error".
     *
     * @return string 'ok' or error message
     */
    private function checkOpenRegister(): string
    {
        try {
            if ($this->appManager->isEnabledForUser('openregister') === true) {
                return 'ok';
            }

            return 'failed: app not enabled';
        } catch (\Exception $e) {
            $this->logger->error('[HealthController] OpenRegister check failed', ['error' => $e->getMessage()]);
            return 'failed: '.$e->getMessage();
        }
    }//end checkOpenRegister()

    /**
     * Check filesystem access.
     *
     * @return string 'ok' or error message
     */
    private function checkFilesystem(): string
    {
        try {
            $tmpFile = sys_get_temp_dir().'/procest_health_'.getmypid();
            $written = file_put_contents($tmpFile, 'health');
            if ($written === false) {
                return 'failed: cannot write to temp directory';
            }

            unlink($tmpFile);

            return 'ok';
        } catch (\Exception $e) {
            return 'failed: '.$e->getMessage();
        }
    }//end checkFilesystem()

    /**
     * Get the app version.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion(Application::APP_ID);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()
}//end class
