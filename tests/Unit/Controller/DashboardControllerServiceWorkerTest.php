<?php

/**
 * DashboardController PWA-asset Unit Tests
 *
 * Covers the one property of `serviceWorker()` that is invisible in every
 * other test: the Content-Security-Policy on the SCRIPT response. A Service
 * Worker inherits the CSP of its own script, not of the page that registered
 * it, and Nextcloud's default for a controller response is
 * `default-src 'none'` with no `connect-src` — under which every `fetch()`
 * the worker makes is blocked and rejects with `TypeError: Failed to fetch`.
 * Both strategies in `public/service-worker.js` then degrade to
 * `Response.error()`, so the worker can only ever break a request, never
 * serve one. Nothing in the app reports that; only this assertion does.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\DashboardController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PWA asset endpoints on the procest dashboard controller.
 *
 * @covers \OCA\Procest\Controller\DashboardController
 */
class DashboardControllerServiceWorkerTest extends TestCase
{

    /**
     * Controller under test.
     *
     * @var DashboardController
     */
    private DashboardController $controller;

    /**
     * Build the controller with a mocked request.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $request          = $this->createMock(IRequest::class);
        $this->controller = new DashboardController($request);
    }//end setUp()

    /**
     * The worker script is served, and served as JavaScript.
     *
     * @return void
     */
    public function testServiceWorkerScriptIsServed(): void
    {
        $response = $this->controller->serviceWorker();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('/', $response->getHeaders()['Service-Worker-Allowed']);
    }//end testServiceWorkerScriptIsServed()

    /**
     * The script response MUST carry a connect-src the worker can use.
     *
     * Asserting the DIRECTIVE, not merely that some policy exists: the broken
     * header was a perfectly well-formed policy that happened to forbid every
     * network call the worker makes.
     *
     * @return void
     */
    public function testServiceWorkerScriptGrantsConnectSrc(): void
    {
        $policy = $this->controller->serviceWorker()->getContentSecurityPolicy();

        $this->assertNotNull(
            $policy,
            'serviceWorker() must set an explicit CSP; without one Nextcloud applies '
            .'default-src \'none\' and the worker cannot fetch anything at all.'
        );

        $header = $policy->buildPolicy();
        $this->assertStringContainsString('connect-src', $header);
        // The offline sync strategy talks back to this Nextcloud.
        $this->assertStringContainsString('\'self\'', $header);
        // The tile strategy talks to the BRT achtergrondkaart WMTS host.
        $this->assertStringContainsString('https://service.pdok.nl', $header);
    }//end testServiceWorkerScriptGrantsConnectSrc()

    /**
     * The web manifest endpoint is unaffected by the CSP change.
     *
     * @return void
     */
    public function testWebManifestIsStillServed(): void
    {
        $response = $this->controller->webManifest();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testWebManifestIsStillServed()
}//end class
