<?php

/**
 * Procest Dashboard Controller
 *
 * Thin subclass of the OpenRegister AppHost GenericDashboardController.
 *
 * The SPA shell (`page()` / `catchAll()`) is inherited unchanged from the
 * engine; only the two procest-specific PWA asset endpoints
 * (`serviceWorker()` / `webManifest()`) — required by the
 * mobiel-inspectie-offline Progressive Web App — remain bespoke here.
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
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

/**
 * Controller for the main Procest dashboard page plus the PWA assets.
 *
 * @psalm-suppress UnusedClass
 */
class DashboardController extends GenericDashboardController
{
    /**
     * App-root-relative location of the bundled PWA assets.
     *
     * @var string
     */
    private const PUBLIC_DIR = __DIR__.'/../../public';

    /**
     * Constructor.
     *
     * Supplies the procest app id to the engine base controller so Nextcloud's
     * DI can auto-wire this subclass from `IRequest` alone (the engine base
     * otherwise takes an injected `string $appName` via the Bootstrap factory).
     *
     * @param IRequest $request HTTP request.
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Serve the mobiel-inspectie-offline Service Worker script.
     *
     * Served from the app scope root with the `Service-Worker-Allowed` header
     * so the worker may control the whole `/apps/procest/` scope. Public +
     * no-CSRF because the worker must register before the user is interactive
     * and runs without the SPA's request context.
     *
     * @return DataDownloadResponse The service-worker JavaScript.
     *
     * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function serviceWorker(): DataDownloadResponse
    {
        $body     = (string) @file_get_contents(self::PUBLIC_DIR.'/service-worker.js');
        $response = new DataDownloadResponse($body, 'service-worker.js', 'application/javascript');
        $response->addHeader('Service-Worker-Allowed', '/');
        $status = Http::STATUS_OK;
        if ($body === '') {
            $status = Http::STATUS_NOT_FOUND;
        }

        $response->setStatus($status);
        return $response;
    }//end serviceWorker()

    /**
     * Serve the PWA web app manifest.
     *
     * @return DataDownloadResponse The web app manifest JSON.
     *
     * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function webManifest(): DataDownloadResponse
    {
        $body     = (string) @file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest');
        $response = new DataDownloadResponse($body, 'manifest.webmanifest', 'application/manifest+json');
        $status   = Http::STATUS_OK;
        if ($body === '') {
            $status = Http::STATUS_NOT_FOUND;
        }

        $response->setStatus($status);
        return $response;
    }//end webManifest()
}//end class
