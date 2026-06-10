<?php

/**
 * Procest Tenant Isolation Middleware
 *
 * Sets the Postgres `search_path` for the current request to
 * `public,<tenant_schema>` so any unqualified table reference resolves
 * inside the tenant's schema first. Reads the schema name from the
 * `TenantContext` populated by `TenantContextMiddleware`.
 *
 * Runs LAST in the procest middleware pipeline (Authenticate → Tenant
 * → TenantContext → TenantIsolation) so the search_path is in place
 * before any controller-level query.
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

use InvalidArgumentException;
use OCA\Procest\Service\TenantContext;
use OCA\Procest\Service\TenantSchemaProvisioner;
use OCP\AppFramework\Middleware;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Set the per-request Postgres search_path from the bound tenant schema.
 */
class TenantIsolationMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param TenantContext           $context     Request-scoped tenant context.
     * @param TenantSchemaProvisioner $provisioner Provides identifier validation.
     * @param IDBConnection           $db          Database connection.
     * @param LoggerInterface         $logger      Logger.
     */
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSchemaProvisioner $provisioner,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Apply the per-request search_path before the controller runs.
     *
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     *
     * @return void
     */
    public function beforeController($controller, $methodName): void
    {
        if ($this->context->isBound() === false) {
            return;
        }

        try {
            $schemaName = $this->context->getSchemaName();
        } catch (Throwable $e) {
            return;
        }

        $this->applySearchPath($schemaName);
    }//end beforeController()

    /**
     * Reset the search_path after each controller so leaked connections do not
     * carry a tenant search_path into the next request on the same DB handle.
     *
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     * @param \OCP\AppFramework\Http\Response $response Response.
     *
     * @return \OCP\AppFramework\Http\Response
     */
    public function afterController($controller, $methodName, \OCP\AppFramework\Http\Response $response): \OCP\AppFramework\Http\Response
    {
        $this->resetSearchPath();
        return $response;
    }//end afterController()

    /**
     * Reset the search_path on exception too.
     *
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     * @param \Exception                   $exception  Exception.
     *
     * @return \OCP\AppFramework\Http\Response
     *
     * @throws \Exception
     */
    public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response
    {
        $this->resetSearchPath();
        throw $exception;
    }//end afterException()

    /**
     * Apply `SET LOCAL search_path TO 'public,<schema>'`.
     *
     * @param string $schemaName Schema name (validated).
     *
     * @return void
     */
    public function applySearchPath(string $schemaName): void
    {
        try {
            $this->provisioner->assertSafeIdentifier($schemaName);
        } catch (InvalidArgumentException $e) {
            $this->logger->error(
                'Procest: refusing to apply unsafe search_path',
                ['schemaName' => $schemaName, 'exception' => $e->getMessage()]
            );
            return;
        }

        try {
            // SET LOCAL keeps the change scoped to the current transaction.
            $sql = 'SET search_path TO "'.$schemaName.'", public';
            $this->db->executeStatement($sql);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to set search_path',
                ['schemaName' => $schemaName, 'exception' => $e->getMessage()]
            );
        }
    }//end applySearchPath()

    /**
     * Reset the search_path to `public`.
     *
     * @return void
     */
    public function resetSearchPath(): void
    {
        try {
            $this->db->executeStatement('SET search_path TO public');
        } catch (Throwable $e) {
            $this->logger->info('Procest: failed to reset search_path', ['exception' => $e->getMessage()]);
        }
    }//end resetSearchPath()
}//end class
