<?php

/**
 * Procest middleware-chain registrar.
 *
 * Owns the ordered SaaS middleware chain. Order is behaviour here, not style:
 * TenantContext must resolve the tenant binding before TenantIsolation sets the
 * Postgres search_path, and QuotaEnforcement must run last. Keeping the whole
 * chain in one class makes that ordering reviewable in a single screen.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\Procest\Middleware\MandateValidationMiddleware;
use OCA\Procest\Middleware\QuotaEnforcementMiddleware;
use OCA\Procest\Middleware\TenantClaimValidationMiddleware;
use OCA\Procest\Middleware\TenantContextMiddleware;
use OCA\Procest\Middleware\TenantIsolationMiddleware;
use OCA\Procest\Middleware\TenantMiddleware;
use OCA\Procest\Middleware\ZgwAuthMiddleware;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the ordered SaaS middleware chain.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class MiddlewareRegistrar {
	/**
	 * Register the SaaS middleware chain in dependency order.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(class: ZgwAuthMiddleware::class);
		$context->registerMiddleware(class: TenantMiddleware::class);
		// SaaS chain (member 04): resolve tenant binding then set Postgres
		// search_path. Order matters — Context runs before Isolation.
		$context->registerMiddleware(class: TenantContextMiddleware::class);
		$context->registerMiddleware(class: TenantIsolationMiddleware::class);
		// SaaS chain (member 05): JWT tenant-claim validation against the
		// request-bound tenant. Forged / cross-tenant JWT → 403.
		$context->registerMiddleware(class: TenantClaimValidationMiddleware::class);
		// SaaS chain (member 06): mandate-matrix authorisation gate. Maps the
		// HTTP verb (and URL hints like /transition) to a matrix action key
		// and blocks the request on deny.
		$context->registerMiddleware(class: MandateValidationMiddleware::class);
		// SaaS chain (member 09): per-request quota enforcement (case creation +
		// API calls). Runs last in the SaaS chain.
		$context->registerMiddleware(class: QuotaEnforcementMiddleware::class);
	}//end register()
}//end class
