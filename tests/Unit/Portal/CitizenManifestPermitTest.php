<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/portal-permits-as-held-products/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Task 3.1: the permits a resident holds, as portaliq's products, and "Kenteken wijzigen".
 */
class CitizenManifestPermitTest extends TestCase {
	/**
	 * @return void
	 */
	public function testThePermitsAreProductsOnTheParkingTheme(): void {
		$client = (new PortalContributionProvider())->getContribution(['audience' => 'client']);
		$permits = array_values(array_filter($client['collections'], static fn (array $c): bool => $c['id'] === 'mijnVergunningen'))[0];

		$this->assertSame('permit', $permits['schema']);
		$this->assertSame('portalSubject', $permits['scopeField'], 'a resident reads only the permits they hold');
		$this->assertSame('products', $permits['kind']);
		$this->assertSame('parkeren', $permits['theme']);
		$this->assertSame(['title', 'validFrom', 'validUntil'], [$permits['titleField'], $permits['validFromField'], $permits['validUntilField']]);
		$this->assertSame(['singular' => 'vergunning', 'plural' => 'vergunningen'], $permits['countLabel']);
		$this->assertSame(['status' => 'active'], $permits['defaultFilters'], 'a revoked permit is not shown as held');

		$schema = (new RealSchemaValidator())->schemas['permit']['properties'];
		foreach (array_merge($permits['fields'], $permits['metaFields'], [$permits['titleField'], $permits['validFromField'], $permits['validUntilField']]) as $field) {
			$this->assertArrayHasKey($field, $schema, $field . ' exists on permit');
			$this->assertContains($field, $permits['fields'], $field . ' is projected');
		}

		foreach ($permits['metaFields'] as $field) {
			$this->assertMatchesRegularExpression('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field, 'portaliq drops a dotted meta field');
		}

		$this->assertNotContains('portalSubject', $permits['fields']);
	}//end testThePermitsAreProductsOnTheParkingTheme()

	/**
	 * @return void
	 */
	public function testKentekenWijzigenGoesToDossiqWithThePermit(): void {
		$client = (new PortalContributionProvider())->getContribution(['audience' => 'client']);
		$actions = array_column($client['actions'], null, 'id');
		$action = $actions['changePermitPlate'];

		$this->assertSame('/index.php/apps/dossiq/api/portal/vergunning/kenteken', $action['endpoint']);
		$this->assertSame('POST', $action['method']);
		$this->assertSame('permitId', $action['rowField']);
		$this->assertSame(['permitId', 'nieuwKenteken'], $action['fields']);
		$this->assertSame(['field' => 'kind', 'op' => 'eq', 'value' => 'parkeren-bewoner'], $action['when']);
		$this->assertArrayNotHasKey('type', $action, 'an endpoint action, not an update portaliq would write to the permit');

		$permits = array_values(array_filter($client['collections'], static fn (array $c): bool => $c['id'] === 'mijnVergunningen'))[0];
		$this->assertSame(['changePermitPlate'], $permits['rowActions']);

		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertStringContainsString("'portalPermit#changePlate', 'url' => '/api/portal/vergunning/kenteken', 'verb' => 'POST'", $routes);
	}//end testKentekenWijzigenGoesToDossiqWithThePermit()
}//end class
