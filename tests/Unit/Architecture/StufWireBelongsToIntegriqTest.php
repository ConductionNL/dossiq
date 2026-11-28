<?php

/**
 * Structural guard: dossiq serves no StUF wire URL any more.
 *
 * Integriq owns the StUF wire (decisions 193 and 199). For one release dossiq
 * kept its old URLs as forwarders, because a zaaksysteem holds them in its own
 * configuration. That release has shipped, so the forwarders are gone: a
 * zaaksysteem posts provider requests to integriq's
 * `/api/stuf-zkn/provider/{sourceId}/cases` and `/persons`, and confirmations
 * to `/api/stuf-zkn/confirmations`. This test keeps them gone, and keeps the
 * admin REST surface that stays.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
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
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-go-to-integriq-only
 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-provider-requests-go-to-integriq-only
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Kernel\Contracts\StufIntegriqBridgeInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The StUF wire URLs are integriq's; dossiq keeps only its admin REST.
 *
 * @coversNothing
 */
class StufWireBelongsToIntegriqTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The URLs a zaaksysteem posted to, which integriq now serves.
	 *
	 * @var list<string>
	 */
	private const WIRE_URLS = [
		'/api/stuf/inbound',
		'/api/stuf/inkomend',
		'/api/stuf/cases',
		'/api/stuf/persons',
		'/api/stuf/zaken',
		'/api/stuf/personen',
	];

	/**
	 * The admin REST routes that stay.
	 *
	 * @var array<string, string>
	 */
	private const ADMIN_ROUTES = [
		'stuf#endpoints' => '/api/stuf/endpoints',
		'stuf#messages'  => '/api/stuf/messages',
		'stuf#outbound'  => '/api/stuf/outbound',
	];

	/**
	 * The route table names none of the wire URLs.
	 *
	 * @return void
	 */
	public function testTheRouteTableServesNoStufWireUrl(): void {
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');

		foreach (self::WIRE_URLS as $url) {
			$this->assertStringNotContainsString(
				"'" . $url . "'",
				$routes,
				$url . ' is integriq\'s now; point the zaaksysteem at integriq instead of routing it here.'
			);
		}
	}//end testTheRouteTableServesNoStufWireUrl()

	/**
	 * The admin REST routes stay.
	 *
	 * @return void
	 */
	public function testTheAdminRoutesStay(): void {
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');

		foreach (self::ADMIN_ROUTES as $name => $url) {
			$this->assertMatchesRegularExpression(
				'#\'name\'\s*=>\s*\'' . preg_quote($name, '#') . '\',\s*\'url\'\s*=>\s*\'' . preg_quote($url, '#') . '\'#',
				$routes,
				$name . ' must still route ' . $url . '.'
			);
		}
	}//end testTheAdminRoutesStay()

	/**
	 * Neither the controller nor the bridge contract keeps a forwarder.
	 *
	 * @return void
	 */
	public function testNoForwarderIsLeftBehind(): void {
		$controller = new ReflectionClass(StufController::class);
		foreach (['cases', 'casesLegacyPath', 'persons', 'personsLegacyPath', 'inbound', 'inboundLegacyPath', 'readRawBody'] as $method) {
			$this->assertFalse($controller->hasMethod($method), 'StufController::' . $method . '() forwarded a wire URL; integriq serves it now.');
		}

		$bridge = new ReflectionClass(StufIntegriqBridgeInterface::class);
		foreach (['forwardConfirmation', 'forwardProviderRequest'] as $method) {
			$this->assertFalse($bridge->hasMethod($method), 'StufIntegriqBridgeInterface::' . $method . '() is gone with the forwarders.');
		}

		$this->assertTrue($bridge->hasMethod('vrijBericht'), 'The outbound vrijBericht still goes through the bridge.');
	}//end testNoForwarderIsLeftBehind()
}//end class
