<?php

/**
 * Procest Tenant Controller
 *
 * Controller for managing tenants in a multi-tenant deployment.
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
use OCA\Procest\Service\TenantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for tenant management operations.
 *
 * Provides CRUD operations for tenants, provisioning, and resource usage tracking.
 * Most endpoints are restricted to platform administrators.
 */
class TenantController extends Controller
{
    /**
     * Constructor for the TenantController.
     *
     * @param IRequest      $request       The request object
     * @param TenantService $tenantService The tenant service
     * @param IUserSession  $userSession   The user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private TenantService $tenantService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all tenants.
     *
     * @return JSONResponse List of tenants
     */
    public function index(): JSONResponse
    {
        if ($this->isPlatformAdmin() === false) {
            return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
        }

        // This would list all tenant objects from OpenRegister.
        return new JSONResponse(['success' => true, 'tenants' => []]);
    }//end index()

    /**
     * Create a new tenant.
     *
     * @return JSONResponse The created tenant
     */
    public function create(): JSONResponse
    {
        if ($this->isPlatformAdmin() === false) {
            return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
        }

        $name   = $this->request->getParam('name');
        $oin    = $this->request->getParam('oin');
        $domain = $this->request->getParam('domain');

        if (empty($name) === true) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Tenant name is required'],
                400
            );
        }

        $tenant = $this->tenantService->createTenant($name, $oin, $domain);
        return new JSONResponse(['success' => true, 'tenant' => $tenant]);
    }//end create()

    /**
     * Provision a tenant with register, group, and default schemas.
     *
     * @param string $tenantId The tenant UUID
     *
     * @return JSONResponse The provisioning result
     */
    public function provision(string $tenantId): JSONResponse
    {
        if ($this->isPlatformAdmin() === false) {
            return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
        }

        $result = $this->tenantService->provisionTenant($tenantId);

        if (isset($result['error']) === true) {
            return new JSONResponse(['success' => false, 'error' => $result['error']], 500);
        }

        return new JSONResponse(['success' => true, 'tenant' => $result]);
    }//end provision()

    /**
     * Get resource usage for a tenant.
     *
     * @param string $tenantId The tenant UUID
     *
     * @return JSONResponse The resource usage
     */
    public function usage(string $tenantId): JSONResponse
    {
        if ($this->isPlatformAdmin() === false) {
            return new JSONResponse(['success' => false, 'error' => 'Admin required'], 403);
        }

        $usage = $this->tenantService->getResourceUsage($tenantId);
        return new JSONResponse(['success' => true, 'usage' => $usage]);
    }//end usage()

    /**
     * Get the current user's tenant.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The current tenant
     */
    public function current(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], 401);
        }

        $tenant = $this->tenantService->getTenantForUser($user->getUID());
        if ($tenant === null) {
            return new JSONResponse(
                ['success' => true, 'tenant' => null, 'message' => 'No tenant assigned']
            );
        }

        return new JSONResponse(['success' => true, 'tenant' => $tenant]);
    }//end current()

    /**
     * Check if current user is a platform administrator.
     *
     * @return bool True if admin
     */
    private function isPlatformAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->tenantService->isPlatformAdmin($user->getUID());
    }//end isPlatformAdmin()
}//end class
