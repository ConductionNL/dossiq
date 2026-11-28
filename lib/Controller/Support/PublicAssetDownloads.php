<?php

/**
 * Dossiq public asset downloads.
 *
 * The Progressive Web App's two bundled assets as downloads: the service
 * worker, with the Content-Security-Policy its own fetches run under, and the
 * web manifest; each a 404 when the bundled file is missing. Split out of
 * {@see \OCA\Dossiq\Controller\DashboardController}, which keeps the public
 * routes, their rate limits and why they are public.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;

/**
 * The bundled PWA assets as downloads.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PublicAssetDownloads {

	/**
	 * App-root-relative location of the bundled PWA assets.
	 *
	 * @var string
	 */
	private const PUBLIC_DIR = __DIR__ . '/../../../public';

	/**
	 * The service worker, allowed the whole app scope, under the policy its fetches need.
	 *
	 * THE CSP ON THIS RESPONSE IS LOAD-BEARING. A Service Worker inherits the
	 * Content-Security-Policy of its OWN script response, not the one on the
	 * page that registered it; under Nextcloud's default (`default-src 'none'`
	 * with no `connect-src`) every `fetch()` the worker makes is blocked.
	 *
	 * @return DataDownloadResponse The service-worker JavaScript, or a 404 when it is not bundled.
	 *
	 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function serviceWorker(): DataDownloadResponse {
		$body = $this->readPublicAsset(name: 'service-worker.js');
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
	 * The web manifest.
	 *
	 * @return DataDownloadResponse The manifest, or a 404 when it is not bundled.
	 *
	 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function webManifest(): DataDownloadResponse {
		$body = $this->readPublicAsset(name: 'manifest.webmanifest');
		$status = Http::STATUS_OK;
		if ($body === '') {
			$status = Http::STATUS_NOT_FOUND;
		}

		return new DataDownloadResponse($body, 'manifest.webmanifest', 'application/manifest+json', $status);
	}//end webManifest()

	/**
	 * The contents of a bundled asset, or '' when it is missing or unreadable.
	 *
	 * @param string $name The file name under public/.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function readPublicAsset(string $name): string {
		$path = self::PUBLIC_DIR . '/' . $name;
		if (is_file($path) === false || is_readable($path) === false) {
			return '';
		}

		return (string)file_get_contents($path);
	}//end readPublicAsset()
}//end class
