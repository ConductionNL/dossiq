<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Controller\GenericMetricsController.
 *
 * Mirrors the public signature of the OpenRegister AppHost generic metrics
 * controller (ADR-040) — the admin-only `/api/metrics` exposition. Used only
 * where the openregister runtime is not installed; dossiq's MetricsController
 * extends this class. NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

if (class_exists(GenericMetricsController::class) === false) {
	/**
	 * Stub for the AppHost generic metrics controller — analysis/tests only.
	 */
	class GenericMetricsController extends Controller {

		/**
		 * Constructor.
		 *
		 * @param string $appName The leaf app id.
		 * @param IRequest $request HTTP request.
		 * @param ManifestLoader $manifestLoader Loads the leaf's observability config.
		 * @param MetricsEngine $engine Renders the declarative metrics.
		 */
		public function __construct(
			string $appName,
			IRequest $request,
			private readonly ManifestLoader $manifestLoader,
			private readonly MetricsEngine $engine,
		) {
			parent::__construct(appName: $appName, request: $request);
		}//end __construct()

		/**
		 * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
		 *
		 * Admin-only by the deliberate absence of `#[NoAdminRequired]`.
		 *
		 * @return TextPlainResponse Prometheus text exposition 0.0.4.
		 */
		#[NoCSRFRequired]
		public function index(): TextPlainResponse {
			return new TextPlainResponse('');
		}//end index()
	}//end class
}//end if
