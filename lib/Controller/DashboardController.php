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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the main Procest dashboard page.
 */
class DashboardController extends Controller
{
    /**
     * App-root-relative location of the bundled PWA assets.
     *
     * @var string
     */
    private const PUBLIC_DIR = __DIR__.'/../../public';

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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function page(string $path=''): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()

    /**
     * Serve the mobiel-inspectie-offline Service Worker script.
     *
     * Served from the app scope root with the `Service-Worker-Allowed` header
     * so the worker may control the whole `/apps/procest/` scope. Public +
     * no-CSRF because the worker must register before the user is interactive
     * and runs without the SPA's request context.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return DataDownloadResponse The service-worker JavaScript.
     *
     * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
     */
    #[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
    #[\OCP\AppFramework\Http\Attribute\PublicPage]
    public function serviceWorker(): DataDownloadResponse
    {
        $body = (string) @file_get_contents(self::PUBLIC_DIR.'/service-worker.js');
        $response = new DataDownloadResponse($body, 'service-worker.js', 'application/javascript');
        $response->addHeader('Service-Worker-Allowed', '/');
        $response->setStatus($body === '' ? Http::STATUS_NOT_FOUND : Http::STATUS_OK);
        return $response;
    }//end serviceWorker()

    /**
     * Serve the PWA web app manifest.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return DataDownloadResponse The web app manifest JSON.
     *
     * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
     */
    #[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
    #[\OCP\AppFramework\Http\Attribute\PublicPage]
    public function webManifest(): DataDownloadResponse
    {
        $body = (string) @file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest');
        $response = new DataDownloadResponse($body, 'manifest.webmanifest', 'application/manifest+json');
        $response->setStatus($body === '' ? Http::STATUS_NOT_FOUND : Http::STATUS_OK);
        return $response;
    }//end webManifest()
}//end class
