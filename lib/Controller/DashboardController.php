<?php

/**
 * Procest Dashboard Controller
 *
 * Controller for the main Procest dashboard page.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the main Procest dashboard page.
 */
class DashboardController extends Controller
{
    /**
     * Constructor for the DashboardController.
     *
     * @param IRequest $request The request object
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the manifest-driven SPA shell.
     *
     * Bound to both `/` and the catch-all `/{path}` route so deep links
     * (e.g. `/apps/procest/cases`) serve the same shell; vue-router resolves
     * the actual view client-side in history mode.
     *
     * @param string $path Sub-path segment from the catch-all route; ignored
     *                     server-side, resolved by vue-router on the client.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function page(string $path=''): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()
}//end class
