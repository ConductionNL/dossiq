<?php

/**
 * Procest Quota Enforcement Middleware
 *
 * Decides per-request whether the action is within tenant quota.
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
 * @spec openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Middleware;

use OCA\Procest\Service\TenantContext;
use OCA\Procest\Service\TenantQuotaService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Pre-controller quota enforcement.
 */
class QuotaEnforcementMiddleware extends Middleware {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 * @param TenantContext $context Tenant context.
	 * @param TenantQuotaService $quota Tenant quota service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly TenantContext $context,
		private readonly TenantQuotaService $quota,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enforce tenant quotas before the controller runs.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::beforeController(); this middleware
	 * dispatches on the request URI instead.
	 */
	public function beforeController($controller, $methodName): void {
		if ($this->context->isBound() === false) {
			return;
		}

		$quotaType = $this->resolveQuotaType(
			verb: strtoupper($this->request->getMethod()),
			path: $this->request->getRequestUri()
		);
		if ($quotaType === null) {
			return;
		}

		$tenantId = $this->context->getTenantId();
		$decision = $this->quota->consume(tenantId: $tenantId, quotaType: $quotaType, amount: 1);

		if ($decision['decision'] === TenantQuotaService::DECISION_BLOCK) {
			throw new QuotaExceededException(
				'Tenant quota exceeded for ' . $quotaType,
				429
			);
		}

		if ($decision['decision'] === TenantQuotaService::DECISION_THROTTLE) {
			$this->logger->warning(
				'Procest quota throttled',
				['tenantId' => $tenantId, 'quotaType' => $quotaType]
			);
		}

		if ($decision['soft'] === true) {
			$this->logger->info(
				'Procest quota soft-limit hit',
				['tenantId' => $tenantId, 'quotaType' => $quotaType]
			);
		}
	}//end beforeController()

	/**
	 * Convert a quota-exceeded exception into a JSON response.
	 *
	 * @param \OCP\AppFramework\Controller $controller Controller.
	 * @param string $methodName Method name.
	 * @param \Exception $exception Exception.
	 *
	 * @return \OCP\AppFramework\Http\Response
	 *
	 * @throws \Exception
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName are
	 * fixed by OCP\AppFramework\Middleware::afterException(); only $exception is
	 * inspected.
	 */
	public function afterException($controller, $methodName, \Exception $exception): \OCP\AppFramework\Http\Response {
		if ($exception instanceof QuotaExceededException) {
			return new JSONResponse(
				['success' => false, 'error' => $exception->getMessage()],
				429
			);
		}

		throw $exception;
	}//end afterException()

	/**
	 * Map request to a quota dimension.
	 *
	 * @param string $verb HTTP verb.
	 * @param string $path URI.
	 *
	 * @return string|null
	 */
	public function resolveQuotaType(string $verb, string $path): ?string {
		if ($verb === 'POST' && (str_contains($path, '/api/case') === true || str_contains($path, '/api/cases') === true)) {
			return 'cases_per_month';
		}

		if (str_starts_with($path, '/api/') === true || str_contains($path, '/index.php/apps/procest/api/') === true) {
			return 'api_calls_per_hour';
		}

		return null;
	}//end resolveQuotaType()
}//end class
