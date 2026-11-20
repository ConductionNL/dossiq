<?php

/**
 * The Jeugdwet example case has its handler: Fatma Kaya, the regisseur, so
 * DqSociaalDomein's "Wat nu?" ticks "Kies een behandelaar". The set declares
 * the case itself (the package's example row is written only on an instance
 * that opted into example data), and writes her as a change on that object.
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
	 * A change on the case the set declares sets Fatma Kaya as handler.
	 *
	 * @return void
	 */
	public function testFatmaHandlesTheJeugdwetCase(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$changes = array_column($set['changes'], null, 'key');
		$change  = $changes['jeugdwet-zaak-behandelaar'] ?? null;

		$this->assertNotNull(actual: $change);
		$this->assertSame(expected: ['dossiq', 'case'], actual: [$change['register'], $change['schema']]);
		$this->assertSame(expected: 'jeugdwet-gezin-oosthaven', actual: $change['object']);
		$this->assertArrayNotHasKey(key: 'where', array: $change);
		$this->assertSame(expected: ['assignee' => '{{cast:fatma.uid}}'], actual: $change['set']);
	}//end testFatmaHandlesTheJeugdwetCase()
}//end class
