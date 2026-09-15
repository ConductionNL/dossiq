<?php

/**
 * Dossiq Metrics Controller
 *
 * Thin adopter of the OpenRegister AppHost engine's GenericMetricsController
 * (ADR-040). The Prometheus metric set is declared in `src/manifest.json`
 * (`observability.metrics`) and rendered by the engine through OpenRegister's
 * portable object/table aggregation — no PHP-side JSON aggregation. This
 * subclass exists only because Nextcloud resolves the `metrics#index` route
 * to this app-namespaced class by name.
 *
 * Auth posture is the engine's: there is intentionally NO `#[NoAdminRequired]`
 * / `#[PublicPage]`, so NC requires an admin session (ADR-006). Only
 * `#[NoCSRFRequired]` is set (machine clients carry no CSRF token).
 *
 * The parent class is only autoloaded when NC instantiates this controller on
 * a request to `/api/metrics`, so a disabled / absent OpenRegister does not
 * fatal Nextcloud bootstrap.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Admin-only declarative Prometheus metrics endpoint backed by the AppHost engine.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */
class MetricsController extends GenericMetricsController {

	/**
	 * Constructor.
	 *
	 * Pins the engine's `$appName` to dossiq so the engine reads dossiq's
	 * manifest metric descriptors.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param ManifestLoader $manifestLoader Loads dossiq's observability config.
	 * @param MetricsEngine $engine Renders the declarative metrics.
	 */
	public function __construct(
		IRequest $request,
		ManifestLoader $manifestLoader,
		MetricsEngine $engine
	) {
		parent::__construct(
			appName: Application::APP_ID,
			request: $request,
			manifestLoader: $manifestLoader,
			engine: $engine
		);
	}//end __construct()

	/**
	 * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
	 *
	 * Admin-only by the deliberate absence of `#[NoAdminRequired]`.
	 *
	 * @return TextPlainResponse Prometheus text exposition 0.0.4.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	#[NoCSRFRequired]
	public function index(): TextPlainResponse {
		return parent::index();
	}//end index()
}//end class
