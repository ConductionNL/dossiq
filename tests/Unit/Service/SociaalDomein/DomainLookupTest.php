<?php

/**
 * DomainLookup unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\SociaalDomein
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Service\SociaalDomein\DomainLookup;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DomainLookup.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\DomainLookup
 */
class DomainLookupTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new DomainLookup(
			domain: 'jeugd',
			declaration: ['schema' => 'jeugdCase', 'bsnKey' => 'jeugdigeBsn'],
			bsn: '999993653'
		);

		$this->assertSame(expected: 'jeugd', actual: $value->domain);
		$this->assertSame(expected: ['schema' => 'jeugdCase', 'bsnKey' => 'jeugdigeBsn'], actual: $value->declaration);
		$this->assertSame(expected: '999993653', actual: $value->bsn);
	}//end testPropertiesReadBack()
}//end class
