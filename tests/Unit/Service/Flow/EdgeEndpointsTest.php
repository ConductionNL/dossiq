<?php

/**
 * EdgeEndpoints unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\EdgeEndpoints;
use PHPUnit\Framework\TestCase;

/**
 * A flow edge's endpoints, read and written in the shape they were stored in.
 *
 * @covers \OCA\Dossiq\Service\Flow\EdgeEndpoints
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class EdgeEndpointsTest extends TestCase {

	/**
	 * A single id and a list both read as ids; blanks and non-strings are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAnEndpointReadsAsItsIds(): void {
		$endpoints = new EdgeEndpoints();

		$this->assertSame(expected: ['end'], actual: $endpoints->idsOf(value: 'end'));
		$this->assertSame(expected: ['a', 'b'], actual: $endpoints->idsOf(value: ['a', '', 7, 'b']));
		$this->assertSame(expected: [], actual: $endpoints->idsOf(value: ''));
		$this->assertSame(expected: [], actual: $endpoints->idsOf(value: null));
	}//end testAnEndpointReadsAsItsIds()

	/**
	 * A swap keeps a list a list and answers a single id with the replacement.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testASwapKeepsTheEndpointsShape(): void {
		$endpoints = new EdgeEndpoints();
		$replace = ['from' => 'a', 'to' => 'z'];

		$this->assertSame(expected: ['z', 'b'], actual: $endpoints->swapped(value: ['a', 'b'], replace: $replace));
		$this->assertSame(expected: 'z', actual: $endpoints->swapped(value: 'a', replace: $replace));
	}//end testASwapKeepsTheEndpointsShape()

	/**
	 * New targets keep a list a list; a single id fans out into one numbered edge per target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testNewTargetsKeepTheEdgesShape(): void {
		$endpoints = new EdgeEndpoints();

		$this->assertSame(
			expected: [['id' => 'e', 'from' => 's', 'to' => ['x', 'y']]],
			actual: $endpoints->withTargets(edge: ['id' => 'e', 'from' => 's', 'to' => ['r']], targets: ['x', 'y'])
		);
		$this->assertSame(
			expected: [['id' => 'e', 'from' => 's', 'to' => 'x']],
			actual: $endpoints->withTargets(edge: ['id' => 'e', 'from' => 's', 'to' => 'r'], targets: ['x'])
		);
		$this->assertSame(
			expected: [
				['id' => 'e-1', 'from' => 's', 'to' => 'x'],
				['id' => 'e-2', 'from' => 's', 'to' => 'y'],
			],
			actual: $endpoints->withTargets(edge: ['id' => 'e', 'from' => 's', 'to' => 'r'], targets: ['x', 'y'])
		);
	}//end testNewTargetsKeepTheEdgesShape()
}//end class
