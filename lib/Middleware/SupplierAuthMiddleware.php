<?php

/**
 * Procest Supplier Auth Middleware
 *
 * Validates the supplier portal bearer token on any controller that requires
 * a logged-in supplier. Injects the resolved supplier via the request
 * parameter bag.
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
 * @spec openspec/changes/leverancier-zaakportaal-04-supplier-scope-security/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Middleware;

use OCA\Procest\Service\SupplierScopeService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Supplier-portal session middleware + IP rate-limit guard.
 */
class SupplierAuthMiddleware extends Middleware
{
    /**
     * Rate-limit: requests per minute per IP.
     */
    public const RATE_LIMIT_PER_MINUTE = 100;

    /**
     * Controllers covered by supplier auth — opt-in list.
     *
     * @var array<int, string>
     */
    private const SUPPLIER_CONTROLLERS = [
        \OCA\Procest\Controller\SupplierPortalController::class,
        \OCA\Procest\Controller\SupplierProfileController::class,
        \OCA\Procest\Controller\ContractController::class,
    ];

    /**
     * Backing cache for rate-limit counters.
     *
     * @var ICache
     */
    private ICache $rateCache;

    public function __construct(
        private readonly IRequest $request,
        private readonly SupplierScopeService $scope,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->rateCache = $cacheFactory->createLocal('procest_supplier_rate');
    }

    /**
     * @param \OCP\AppFramework\Controller $controller Controller.
     * @param string                       $methodName Method name.
     *
     * @return void
     */
    public function beforeController($controller, $methodName): void
    {
        if (in_array(get_class($controller), self::SUPPLIER_CONTROLLERS, true) === false) {
            return;
        }

        // Rate limit first — refuse traffic before doing any auth lookup.
        if ($this->bumpAndCheckRateLimit($this->request->getRemoteAddress()) === false) {
            throw new SupplierRateLimitException('Rate limit exceeded', 429);
        }

        $supplier = $this->scope->resolveFromBearer((string) $this->request->getHeader('Authorization'));
        if ($supplier === null) {
            throw new SupplierUnauthorizedException('Bearer token required', 401);
        }

        $this->request->setParameter('_supplierRef', $supplier['supplierRef']);
        $this->request->setParameter('_supplierUserId', $supplier['supplierUserId']);
        $this->request->setParameter('_supplierRole', $supplier['role']);
    }

    /**
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
        if ($exception instanceof SupplierUnauthorizedException) {
            return new JSONResponse(['success' => false, 'error' => $exception->getMessage()], 401);
        }

        if ($exception instanceof SupplierRateLimitException) {
            return new JSONResponse(['success' => false, 'error' => $exception->getMessage()], 429);
        }

        throw $exception;
    }

    /**
     * Bump the per-IP counter; return false when the limit is breached.
     *
     * @param string $ip Client IP.
     *
     * @return bool
     */
    public function bumpAndCheckRateLimit(string $ip): bool
    {
        $key = 'rate:'.$ip;
        try {
            $count = (int) $this->rateCache->get($key);
            $count++;
            $this->rateCache->set($key, $count, 60);
            return $count <= self::RATE_LIMIT_PER_MINUTE;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
