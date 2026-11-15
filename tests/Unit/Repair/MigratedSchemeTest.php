<?php

/**
 * MigratedScheme unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigratedScheme;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MigratedScheme.
 *
 * @covers \OCA\Dossiq\Repair\MigratedScheme
 */
class MigratedSchemeTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$value = new MigratedScheme(
			scheme: ['schemeName' => 'Innovatiefonds'],
			name: 'Innovatiefonds',
			caseTypeId: 'type-1',
			register: '5',
			output: $output
		);

		$this->assertSame(expected: ['schemeName' => 'Innovatiefonds'], actual: $value->scheme);
		$this->assertSame(expected: 'Innovatiefonds', actual: $value->name);
		$this->assertSame(expected: 'type-1', actual: $value->caseTypeId);
		$this->assertSame(expected: '5', actual: $value->register);
		$this->assertSame(expected: $output, actual: $value->output);
	}//end testPropertiesReadBack()
}//end class
