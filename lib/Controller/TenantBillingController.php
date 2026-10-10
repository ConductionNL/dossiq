<?php

/**
 * Dossiq Tenant Billing Controller
 *
 * The two metered-billing endpoints: a tenant's month summary and the
 * Shillinq invoicing run. They lived on the tenant admin controller until
 * the tenant admin store retired (tenancy-onto-openregister-organisation 6.9).
 * Billing stays in dossiq by decision 2b, so its endpoints stay too, at the
 * same URLs. Admin-only.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/tenant-billing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tenant billing summary and invoicing.
 *
 * @spec openspec/specs/tenant-billing/spec.md
 */
class TenantBillingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request        HTTP request.
	 * @param TenantBillingService $billingService Tenant billing service.
	 */
	public function __construct(
		IRequest $request,
		private readonly TenantBillingService $billingService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The billing summary of one tenant for one month.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month    YYYY-MM.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function summary(string $tenantId, string $month): JSONResponse {
		try {
			$summary = $this->billingService->getMonthBilling(tenantId: $tenantId, month: $month);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true, 'summary' => $summary]);
	}//end summary()

	/**
	 * Run monthly invoicing for a tenant: aggregate unbilled usage, export a
	 * Shillinq invoice, and stamp the events.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month    YYYY-MM.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/tenant-billing/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function run(string $tenantId, string $month): JSONResponse {
		try {
			$result = $this->billingService->runInvoicing(tenantId: $tenantId, month: $month);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true, 'invoice' => $result]);
	}//end run()
}//end class
