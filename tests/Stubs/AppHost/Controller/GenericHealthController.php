<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Controller\GenericHealthController.
 *
 * Mirrors the public signature of the OpenRegister AppHost generic health
 * controller (ADR-040) — the declarative `/api/health` probe. Used only where
 * the openregister runtime is not installed; dossiq's HealthController extends
 * this class. NOT scanned by PHPCS.
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

use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

if (class_exists(GenericHealthController::class) === false) {
	/**
	 * Stub for the AppHost generic health controller — analysis/tests only.
	 */
	class GenericHealthController extends Controller {

		/**
		 * Constructor.
		 *
		 * @param string $appName The leaf app id.
		 * @param IRequest $request HTTP request.
		 * @param ManifestLoader $manifestLoader Loads the leaf's observability config.
		 * @param HealthCheckExecutor $executor Runs the declarative checks.
		 */
		public function __construct(
			string $appName,
			IRequest $request,
			private readonly ManifestLoader $manifestLoader,
			private readonly HealthCheckExecutor $executor,
		) {
			parent::__construct(appName: $appName, request: $request);
		}//end __construct()

		/**
		 * GET /api/health — declarative health check (ADR-006), public probe.
		 *
		 * The ceiling is the engine's own: a health endpoint is polled on a
		 * short interval, so a tight limit turns the probe into the outage.
		 *
		 * @return JSONResponse The `{status, app, version, checks}` body.
		 */
		#[PublicPage]
		#[NoCSRFRequired]
		#[AnonRateLimit(limit: 240, period: 60)]
		public function index(): JSONResponse {
			return new JSONResponse(['status' => 'unknown', 'app' => $this->appName, 'checks' => []]);
		}//end index()
	}//end class
}//end if
