<?php

/**
 * Procest Dashboard Controller
 *
 * SPA host implemented by COMPOSITION, not inheritance. The SPA shell
 * (`page()` / `catchAll()`) is behaviourally identical to the OpenRegister
 * AppHost `GenericDashboardController` this class used to subclass, but is
 * implemented locally against OCP only. The two procest-specific PWA asset
 * endpoints (`serviceWorker()` / `webManifest()`) — required by the
 * mobiel-inspectie-offline Progressive Web App — remain bespoke here.
 *
 * ⚠️ DO NOT "simplify" this back into a subclass of the AppHost generic, and do
 * not `use`-import an OpenRegister class here. Nextcloud's router
 * `ReflectionClass()`es every file in `lib/Controller/` while MATCHING a route,
 * so an unresolvable parent makes EVERY route in procest return HTTP 500 —
 * including routes with no OpenRegister involvement at all. Procest does not
 * declare `<app>openregister</app>`, so an admin can create exactly that
 * configuration. `extends` is resolved by the AUTOLOADER, not the DI container,
 * so no amount of lazy registration can rescue it. See decidesk#377 / #388.
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

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the main Procest dashboard page plus the PWA assets.
 *
 * @psalm-suppress UnusedClass
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
     * Constructor.
     *
     * Supplies the procest app id so Nextcloud's DI can auto-wire this
     * controller from `IRequest` alone.
     *
     * @param IRequest $request HTTP request.
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main SPA page from `templates/index.php`.
     *
     * `#[NoAdminRequired]` / `#[NoCSRFRequired]` were previously INHERITED from
     * the AppHost generic; they are declared explicitly here so the auth posture
     * is byte-for-byte unchanged by dropping the inheritance.
     *
     * @return TemplateResponse The rendered procest index template.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        return $this->renderIndex();
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @return TemplateResponse The rendered procest index template.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()

    /**
     * Build the `index` TemplateResponse.
     *
     * @return TemplateResponse The rendered procest index template.
     */
    protected function renderIndex(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');
    }//end renderIndex()

    /**
     * Serve the mobiel-inspectie-offline Service Worker script.
     *
     * Served from the app scope root with the `Service-Worker-Allowed` header
     * so the worker may control the whole `/apps/procest/` scope. Public +
     * no-CSRF because the worker must register before the user is interactive
     * and runs without the SPA's request context.
     *
     * ⚠️ THE CSP ON THIS RESPONSE IS LOAD-BEARING. A Service Worker inherits
     * the Content-Security-Policy of its OWN script response, not the one on
     * the page that registered it. Nextcloud's default for a controller
     * response is an EmptyContentSecurityPolicy — `default-src 'none'` with no
     * `connect-src` — under which EVERY `fetch()` the worker makes is blocked
     * and rejects with `TypeError: Failed to fetch`. Measured on a Nextcloud
     * 32 instance: with the default policy, `fetch(request)`,
     * `fetch(request.url)` and `fetch(url, {mode: 'same-origin'})` all threw
     * inside the worker, so both strategies in `public/service-worker.js`
     * (`cacheFirst` / `networkFirst`) could never populate a cache and always
     * fell through to `Response.error()`. Any request the worker claimed with
     * `respondWith()` was therefore guaranteed to fail in the page.
     *
     * @return DataDownloadResponse The service-worker JavaScript.
     *
     * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function serviceWorker(): DataDownloadResponse
    {
        $body   = $this->readPublicAsset(name: 'service-worker.js');
        $status = Http::STATUS_OK;
        if ($body === '') {
            $status = Http::STATUS_NOT_FOUND;
        }

        $response = new DataDownloadResponse($body, 'service-worker.js', 'application/javascript', $status);
        $response->addHeader('Service-Worker-Allowed', '/');

        $csp = new EmptyContentSecurityPolicy();
        // The offline sync strategy talks back to this Nextcloud.
        $csp->addAllowedConnectDomain('\'self\'');
        // The tile strategy talks to the BRT achtergrondkaart WMTS host, which
        // is the only third-party host public/service-worker.js will fetch.
        $csp->addAllowedConnectDomain('https://service.pdok.nl');
        $response->setContentSecurityPolicy($csp);

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
        $body   = $this->readPublicAsset(name: 'manifest.webmanifest');
        $status = Http::STATUS_OK;
        if ($body === '') {
            $status = Http::STATUS_NOT_FOUND;
        }

        return new DataDownloadResponse($body, 'manifest.webmanifest', 'application/manifest+json', $status);
    }//end webManifest()

    /**
     * Read a static asset shipped under the app's public directory.
     *
     * Returns an empty string when the asset is absent or unreadable; callers
     * translate that into a 404. Guarding with is_file()/is_readable() keeps
     * the missing-asset path free of PHP warnings without an `@` operator.
     *
     * @param string $name Bare file name inside the public directory.
     *
     * @return string The asset contents, or '' when it cannot be read.
     */
    private function readPublicAsset(string $name): string
    {
        $path = self::PUBLIC_DIR.'/'.$name;
        if (is_file($path) === false || is_readable($path) === false) {
            return '';
        }

        return (string) file_get_contents($path);
    }//end readPublicAsset()
}//end class
