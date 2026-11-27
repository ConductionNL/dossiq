<?php

/**
 * SurfaceLoadEvents and PublicAssetDownloads Unit Tests
 *
 * A page dispatches the Viewer's load event and the bare files page the Files
 * app's, each only when its app is installed; the service worker comes with
 * the app scope and the policy its fetches need, the manifest as its own type.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\PublicAssetDownloads;
use OCA\Dossiq\Controller\Support\SurfaceLoadEvents;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SurfaceLoadEvents and PublicAssetDownloads.
 *
 * @covers \OCA\Dossiq\Controller\Support\SurfaceLoadEvents
 * @covers \OCA\Dossiq\Controller\Support\PublicAssetDownloads
 */
final class SurfaceLoadEventsTest extends TestCase {

	/**
	 * Each page dispatches its own events, only those whose class exists.
	 *
	 * @return void
	 */
	public function testEachPageDispatchesItsOwnInstalledEvents(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->any())->method('dispatchTyped')->willReturnCallback(
			callback: static function (Event $event) use (&$dispatched): void {
				$dispatched[] = $event::class;
			}
		);
		$surfaces = new SurfaceLoadEvents(eventDispatcher: $dispatcher);

		$surfaces->forPage();
		$this->assertSame(
			expected: array_values(array: array_filter(array: SurfaceLoadEvents::PAGE_SURFACE_EVENTS, callback: 'class_exists')),
			actual: $dispatched
		);

		$dispatched = [];
		$surfaces->forFiles();
		$this->assertSame(
			expected: array_values(array: array_filter(array: SurfaceLoadEvents::FILES_SURFACE_EVENTS, callback: 'class_exists')),
			actual: $dispatched
		);
	}//end testEachPageDispatchesItsOwnInstalledEvents()

	/**
	 * The page carries the Viewer's event and the files page the Files app's, nothing else.
	 *
	 * @return void
	 */
	public function testThePageEventsAreTheViewersAndTheFilesEventsTheFilesApps(): void {
		$this->assertSame(expected: ['OCA\\Viewer\\Event\\LoadViewer'], actual: SurfaceLoadEvents::PAGE_SURFACE_EVENTS);
		$this->assertSame(expected: ['OCA\\Files\\Event\\LoadAdditionalScriptsEvent'], actual: SurfaceLoadEvents::FILES_SURFACE_EVENTS);
	}//end testThePageEventsAreTheViewersAndTheFilesEventsTheFilesApps()

	/**
	 * The service worker is allowed the whole scope and may fetch this Nextcloud; the manifest has its own type.
	 *
	 * @return void
	 */
	public function testTheAssetsComeWithTheirScopeTypeAndPolicy(): void {
		$assets = new PublicAssetDownloads();

		$worker = $assets->serviceWorker();
		$this->assertSame(expected: Http::STATUS_OK, actual: $worker->getStatus());
		$this->assertSame(expected: '/', actual: ($worker->getHeaders()['Service-Worker-Allowed'] ?? null));
		$this->assertStringContainsString(
			needle: "'self'",
			haystack: $worker->getContentSecurityPolicy()->buildPolicy()
		);

		$manifest = $assets->webManifest();
		$this->assertSame(expected: Http::STATUS_OK, actual: $manifest->getStatus());
		$this->assertStringContainsString(
			needle: 'application/manifest+json',
			haystack: (string)($manifest->getHeaders()['Content-Type'] ?? '')
		);
	}//end testTheAssetsComeWithTheirScopeTypeAndPolicy()
}//end class
