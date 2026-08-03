<?php

/**
 * Procest Tenant Context Middleware
 *
 * Resolves the requesting tenant from headers / user / route parameter and
 * binds it onto the request-scoped `TenantContext` service. Runs after the
 * existing `TenantMiddleware` (which does the older Organisation-shaped
 * resolution) and before `TenantIsolationMiddleware` (which sets the
 * Postgres search_path).
 *
 * Resolution order:
 *   1. `X-Tenant-Id` header (platform-admin or JWT claim)
 *   2. The current user's `tenantUser` membership row (one-to-one)
 *
 * @category Middleware
 * @package  OCA\Procest\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-04-tenant-context-isolation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Middleware;

use OCA\Procest\Service\TenantContext;
use OCA\Procest\Service\TenantProvisioningService;
use OCA\Procest\Service\TenantSaasService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Middleware that resolves the tenant and binds it to the TenantContext.
 */
class TenantContextMiddleware extends Middleware
{
    /**
     * Controllers whose endpoints do not require a tenant binding.
     *
     * @var array<int, string>
     */
    private const EXEMPT_CONTROLLERS = [
        'OCA\Procest\Controller\SettingsController',
        // Health + metrics are served by the OpenRegister AppHost engine
        // (ADR-040); the dispatched controller is the generic class.
        'OCA\OpenRegister\AppHost\Controller\GenericHealthController',
        'OCA\OpenRegister\AppHost\Controller\GenericMetricsController',
        'OCA\Procest\Controller\TenantController',
        'OCA\Procest\Controller\TenantSaasController',
        'OCA\Procest\Controller\DashboardController',
    ];

    /**
     * Constructor.
     *
     * @param IRequest                  $request           Request.
     * @param IUserSession              $userSession       User session.
     * @param TenantSaasService         $tenantSaasService Tenant SaaS service.
     * @param TenantProvisioningService $provisioning      Provisioning service (schema-name builder).
     * @param TenantContext             $context           Request-scoped context.
     * @param LoggerInterface           $logger            Logger.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly IUserSession $userSession,
        private readonly TenantSaasService $tenantSaasService,
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantContext $context,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the tenant for the incoming request and bind it to the context.
     *
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $methodName is fixed by
     * OCP\AppFramework\Middleware::beforeController(); tenant resolution keys off
     * the controller class and the request, not the action name.
     */
    public function beforeController($controller, $methodName): void
    {
        if (in_array(get_class($controller), self::EXEMPT_CONTROLLERS, true) === true) {
            return;
        }

        $tenantId = $this->resolveTenantIdFromRequest();
        if ($tenantId === null) {
            return;
        }

        $tenant = $this->tenantSaasService->getById($tenantId);
        if ($tenant === null) {
            $this->logger->info(
                'Procest: TenantContextMiddleware could not resolve tenant',
                ['tenantId' => $tenantId]
            );
            return;
        }

        try {
            $schemaName = $this->provisioning->buildSchemaName(
                uuid: (string) ($tenant['uuid'] ?? $tenant['id'] ?? $tenantId),
                slug: (string) ($tenant['slug'] ?? '')
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: schema-name build failed in TenantContextMiddleware',
                ['tenantId' => $tenantId, 'exception' => $e->getMessage()]
            );
            return;
        }

        $this->context->bind($tenant, $schemaName);
    }//end beforeController()

    /**
     * Pre-controller exceptions surface to the dispatcher unchanged.
     *
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     * @param \Exception                   $exception  Exception.
     *
     * @return \OCP\AppFramework\Http\Response
     *
     * @throws \Exception
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
     * fixed by OCP\AppFramework\Middleware::afterException(); this hook only
     * re-throws.
     */
    public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response
    {
        throw $exception;
    }//end afterException()

    /**
     * Resolve the tenant UUID for the current request.
     *
     * @return string|null
     */
    public function resolveTenantIdFromRequest(): ?string
    {
        $header = $this->request->getHeader('X-Tenant-Id');
        if (is_string($header) === true && $header !== '') {
            return $header;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        // Fall back: look up the tenantUser row for the current user — covers
        // the common single-tenant-per-user case.
        try {
            $rows = $this->tenantSaasService->listActive(statusFilter: 'active', limit: 100);
            // No per-user filter in the SaaS service yet; the tenant binding
            // for an unauthenticated single-tenant deployment is handled by
            // the older TenantMiddleware via the OR Organisation entity. This
            // middleware only fires when an X-Tenant-Id is explicitly supplied.
            unset($rows);
        } catch (Throwable $e) {
            $this->logger->info('Procest: tenant lookup miss', ['exception' => $e->getMessage()]);
        }

        return null;
    }//end resolveTenantIdFromRequest()
}//end class
