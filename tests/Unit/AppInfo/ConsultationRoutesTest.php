<?php

/**
 * Consultation route tests.
 *
 * The token-addressed external consultation surface was deleted, not disabled.
 * A route left behind whose controller is gone answers 500 through a
 * ReflectionException rather than 404, and a controller left behind with no
 * route is dead code that reads as a feature. Both directions are asserted
 * here, together with the two routes that replaced them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ConsultationRoutesTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @return string The absolute path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every route dossiq registers, by `controller#method`.
	 *
	 * @return array<int, string> The route names.
	 */
	private function routeNames(): array {
		$routes = require $this->root() . '/appinfo/routes.php';
		self::assertIsArray($routes, 'appinfo/routes.php must return the route table.');

		$names = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$names[] = (string)($route['name'] ?? '');
		}

		self::assertNotSame([], $names, 'An empty route table would make every assertion below vacuous.');

		return $names;
	}//end routeNames()

	/**
	 * The token-addressed consultation surface is gone: no routes, no
	 * controller, no Vue page, no manifest fragment.
	 *
	 * @return void
	 */
	public function testTheTokenAddressedConsultationSurfaceIsGone(): void {
		foreach ($this->routeNames() as $name) {
			self::assertStringNotContainsString(
				'consultationPublic#',
				$name,
				'A route whose controller was deleted answers 500, not 404.'
			);
		}

		$gone = [
			'lib/Controller/ConsultationPublicController.php',
			'src/views/public/ExternalConsultationResponsePage.vue',
			'src/manifest.d/consultation-public.json',
		];

		foreach ($gone as $path) {
			self::assertFileDoesNotExist($this->root() . '/' . $path);
		}
	}//end testTheTokenAddressedConsultationSurfaceIsGone()

	/**
	 * Nothing looks a consultation up by a token any more, because nothing
	 * ever minted one.
	 *
	 * @return void
	 */
	public function testNothingResolvesAConsultationBySecureToken(): void {
		$sources = [
			'lib/Service/ConsultationService.php',
			'lib/Service/Consultation/ConsultationRepository.php',
		];

		foreach ($sources as $path) {
			self::assertStringNotContainsString(
				'function findBySecureToken',
				(string)file_get_contents($this->root() . '/' . $path),
				$path . ' still resolves a token nothing mints.'
			);
		}
	}//end testNothingResolvesAConsultationBySecureToken()

	/**
	 * The two routes that replaced it are registered, and their methods exist
	 * on the controller they name.
	 *
	 * @return void
	 */
	public function testTheAccessLinkConsultationRoutesAreRegistered(): void {
		$names = $this->routeNames();

		foreach (['consultation#externalLink', 'consultation#collectAdvice'] as $route) {
			self::assertContains($route, $names);
		}

		$controller = (string)file_get_contents($this->root() . '/lib/Controller/ConsultationController.php');
		self::assertStringContainsString('public function externalLink(', $controller);
		self::assertStringContainsString('public function collectAdvice(', $controller);
	}//end testTheAccessLinkConsultationRoutesAreRegistered()

	/**
	 * The three access-link routes are registered, and their methods exist on
	 * CaseSharingController.
	 *
	 * @return void
	 */
	public function testTheAccessLinkSharingRoutesAreRegistered(): void {
		$names = $this->routeNames();

		foreach (['caseSharing#listLinks', 'caseSharing#pauseLink', 'caseSharing#previewLink'] as $route) {
			self::assertContains($route, $names);
		}

		$controller = (string)file_get_contents($this->root() . '/lib/Controller/CaseSharingController.php');
		foreach (['listLinks', 'pauseLink', 'previewLink'] as $method) {
			self::assertStringContainsString('public function ' . $method . '(', $controller);
		}
	}//end testTheAccessLinkSharingRoutesAreRegistered()
}//end class
