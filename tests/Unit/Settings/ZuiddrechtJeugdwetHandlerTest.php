<?php

/**
 * The Jeugdwet example case has its handler: Fatma Kaya, the regisseur, so
 * DqSociaalDomein's "Wat nu?" ticks "Kies een behandelaar". The package's own
 * row names her too, but its import runs before the set creates her account,
 * so the set writes her as a change on the case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example handler
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Jeugdwet case's handler.
 */
class ZuiddrechtJeugdwetHandlerTest extends TestCase {

	/**
	 * A change on the case by its title sets Fatma Kaya as handler.
	 *
	 * @return void
	 */
	public function testFatmaHandlesTheJeugdwetCase(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$changes = array_column($set['changes'], null, 'key');
		$change  = $changes['jeugdwet-zaak-behandelaar'] ?? null;

		$this->assertNotNull($change);
		$this->assertSame(['dossiq', 'case'], [$change['register'], $change['schema']]);
		$this->assertSame(['title' => 'Melding Jeugdwet gezin Oosthaven'], $change['where']);
		$this->assertSame(['assignee' => '{{cast:fatma.uid}}'], $change['set']);
	}//end testFatmaHandlesTheJeugdwetCase()
}//end class
