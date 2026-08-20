<?php

/**
 * Procest Tenant Controller
 *
 * Controller for tenant provisioning, usage aggregation, and current-tenant
 * resolution. Generic tenant CRUD (list/create/update/delete) is delegated
 * to OpenRegister via the manifest renderer; this controller only owns
 * the multi-tenant domain logic that cannot be expressed declaratively.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/multi-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\TenantService;
use OCA\Procest\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for tenant domain operations (provisioning, usage, current tenant).
 *
 * Generic CRUD (list/create/update/destroy) is no longer routed here — manifest
 * pages call the OpenRegister object endpoints directly. Only the three domain
 * methods below remain: they wrap provisioning workflow, resource-usage
 * aggregation, and current-tenant resolution.
 */
class TenantController extends Controller {
	/**
	 * Constructor for the TenantController.
	 *
	 * @param IRequest $request The request object
	 * @param TenantService $tenantService The tenant service
	 * @param IUserSession $userSession The user session
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
	 * Provision a tenant with register, group, and default schemas.
	 *
	 * @param string $tenantId The tenant UUID
	 *
	 * @return JSONResponse The provisioning result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function provision(string $tenantId): JSONResponse {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function usage(string $tenantId): JSONResponse {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function current(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
	private function isPlatformAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->tenantService->isPlatformAdmin($user->getUID());
	}//end isPlatformAdmin()
}//end class
