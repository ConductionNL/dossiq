<?php

/**
 * Procest Tenant Middleware
 *
 * Middleware for enforcing multi-tenant data isolation.
 *
 * @category Middleware
 * @package  OCA\Procest\Middleware
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

namespace OCA\Procest\Middleware;

use OCA\Procest\Service\TenantService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Middleware that resolves and enforces tenant context for all requests.
 *
 * Ensures that users can only access data belonging to their tenant.
 * Platform admins can access any tenant via context switching.
 * Returns 404 (not 403) for cross-tenant access to prevent information leakage.
 */
class TenantMiddleware extends Middleware
{
    /**
     * Controllers that are exempt from tenant enforcement.
     */
    private const EXEMPT_CONTROLLERS = [
        'OCA\Procest\Controller\SettingsController',
        'OCA\Procest\Controller\HealthController',
        'OCA\Procest\Controller\MetricsController',
        'OCA\Procest\Controller\DashboardController',
        'OCA\Procest\Controller\TenantController',
    ];

    /**
     * Constructor for the TenantMiddleware.
     *
     * @param TenantService   $tenantService The tenant service
     * @param IUserSession    $userSession   The user session
     * @param IRequest        $request       The request object
     * @param LoggerInterface $logger        The logger
     *
     * @return void
     */
    public function __construct(
        private TenantService $tenantService,
        private IUserSession $userSession,
        private IRequest $request,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check tenant context before controller execution.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     *
     * @return void
     */
    public function beforeController($controller, $methodName): void
    {
        // Skip for exempt controllers.
        $controllerClass = get_class($controller);
        if (in_array($controllerClass, self::EXEMPT_CONTROLLERS) === true) {
            return;
        }

        // Skip for public pages (no user session).
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $userId = $user->getUID();

        // Platform admins bypass tenant restrictions.
        if ($this->tenantService->isPlatformAdmin($userId) === true) {
            return;
        }

        // Resolve tenant for the current user.
        $tenant = $this->tenantService->getTenantForUser($userId);
        if ($tenant === null) {
            $this->logger->warning(
                'Procest: User has no tenant assigned',
                ['userId' => $userId]
            );
            // Allow access even without tenant (single-tenant deployments).
            return;
        }

        // Store tenant context for controllers to use.
        $this->request->setParameter('_tenantId', $tenant['uuid'] ?? $tenant['id'] ?? '');
        $this->request->setParameter('_tenantRegisterId', $tenant['registerId'] ?? '');
        $this->request->setParameter('_tenantSlug', $tenant['slug'] ?? '');
    }//end beforeController()

    /**
     * Handle exceptions from controllers.
     *
     * @param \OCP\AppFramework\Controller $controller The controller
     * @param string                       $methodName The method name
     * @param \Exception                   $exception  The exception
     *
     * @return JSONResponse The error response
     *
     * @throws \Exception Re-throws if not a tenant exception
     */
    public function afterException($controller, $methodName, \Exception $exception): JSONResponse
    {
        if ($exception->getCode() === 404) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Not found'],
                404
            );
        }

        throw $exception;
    }//end afterException()
}//end class
