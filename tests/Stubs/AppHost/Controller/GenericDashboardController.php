<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Controller\GenericDashboardController.
 *
 * Mirrors the public signature of the OpenRegister AppHost generic dashboard
 * controller (ADR-040) — the SPA page + history-mode catch-all. Used only
 * where the openregister runtime is not installed; procest's
 * DashboardController extends this class. No-ops to the leaf `index` template
 * in the stub. NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Stubs\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

if (class_exists(GenericDashboardController::class) === false) {
    /**
     * Stub for the AppHost generic dashboard controller — analysis/tests only.
     */
    class GenericDashboardController extends Controller
    {

        /**
         * Constructor.
         *
         * @param string   $appName The leaf app id.
         * @param IRequest $request HTTP request.
         */
        public function __construct(string $appName, IRequest $request)
        {
            parent::__construct(appName: $appName, request: $request);
        }//end __construct()

        /**
         * Render the main SPA page.
         *
         * @return TemplateResponse The rendered template.
         */
        public function page(): TemplateResponse
        {
            return new TemplateResponse($this->appName, 'index');
        }//end page()

        /**
         * Serve the SPA for deep links (Vue history mode).
         *
         * @return TemplateResponse The rendered template.
         */
        public function catchAll(): TemplateResponse
        {
            return $this->page();
        }//end catchAll()
    }//end class
}//end if
