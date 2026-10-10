<?php

/**
 * Dossiq Tenant Context
 *
 * Request-scoped holder of the request's tenant: UUID, slug, status and the
 * full tenant row. The tenant is OpenRegister's active organisation, when the
 * user's `tenantUser` memberships list it (Q3, Ruben 2026-10-08). The context
 * resolves it itself, on first read, through `TenantSessionService`; no
 * middleware binds it. It carries no database schema name: tenant isolation
 * is OpenRegister's organisation row filter.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use RuntimeException;

/**
 * Request-scoped tenant context.
 *
 * Its lifetime is the request: the NC DI container builds one per request,
 * so the active organisation is read at most once per request.
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */
class TenantContext {

	/**
	 * Whether the tenant has been read for this request.
	 *
	 * @var boolean
	 */
	private bool $resolved = false;

	/**
	 * The tenant row, or null when the request has no tenant.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $tenant = null;

	/**
	 * Constructor.
	 *
	 * @param TenantSessionService $session Answers OpenRegister's active organisation, membership checked.
	 */
	public function __construct(
		private readonly TenantSessionService $session,
	) {
	}//end __construct()

	/**
	 * Whether the request has a tenant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function isBound(): bool {
		return $this->resolve() !== null;
	}//end isBound()

	/**
	 * Get the tenant row.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getTenant(): array {
		return $this->assertBound();
	}//end getTenant()

	/**
	 * Get the tenant UUID.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getTenantId(): string {
		$tenant = $this->assertBound();
		return (string)($tenant['uuid'] ?? $tenant['id'] ?? '');
	}//end getTenantId()

	/**
	 * Get the tenant slug.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getSlug(): string {
		return (string)($this->assertBound()['slug'] ?? '');
	}//end getSlug()

	/**
	 * Get the organisation's lifecycle status, as stored in OpenRegister.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the request has no tenant.
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function getStatus(): string {
		return (string)($this->assertBound()['status'] ?? '');
	}//end getStatus()

	/**
	 * Forget the tenant, so the next read asks again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/tenant-organisation-boundary/spec.md
	 */
	public function reset(): void {
		$this->resolved = false;
		$this->tenant = null;
	}//end reset()

	/**
	 * Read the tenant once per request.
	 *
	 * @return array<string,mixed>|null The tenant, or null.
	 */
	private function resolve(): ?array {
		if ($this->resolved === false) {
			$this->tenant = $this->session->activeTenant();
			$this->resolved = true;
		}

		return $this->tenant;
	}//end resolve()

	/**
	 * The tenant, or an exception when the request has none.
	 *
	 * @return array<string,mixed> The tenant.
	 *
	 * @throws RuntimeException When the request has no tenant.
	 */
	private function assertBound(): array {
		$tenant = $this->resolve();
		if ($tenant === null) {
			throw new RuntimeException('No tenant bound to the current request');
		}

		return $tenant;
	}//end assertBound()
}//end class
