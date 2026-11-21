<?php

/**
 * DottedPath unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sync
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

namespace OCA\Dossiq\Tests\Unit\Service\Sync;

use OCA\Dossiq\Service\Sync\DottedPath;
use PHPUnit\Framework\TestCase;

/**
 * A value read or set at a dot path.
 *
 * @covers \OCA\Dossiq\Service\Sync\DottedPath
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DottedPathTest extends TestCase {

	/**
	 * A path reads through maps and lists; a missing step is null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAValueIsReadThroughMapsAndLists(): void {
		$data = ['responses' => [['comment' => 'eerste'], ['comment' => 'tweede']], 'title' => 'Melding'];
		$paths = new DottedPath();

		$this->assertSame(expected: 'tweede', actual: $paths->valueAt(data: $data, path: 'responses.1.comment'));
		$this->assertSame(expected: 'Melding', actual: $paths->valueAt(data: $data, path: 'title'));
		$this->assertNull(actual: $paths->valueAt(data: $data, path: 'responses.2.comment'));
		$this->assertNull(actual: $paths->valueAt(data: $data, path: 'title.text'));
	}//end testAValueIsReadThroughMapsAndLists()

	/**
	 * A value is set in place, and the steps a path names are made when missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAValueIsSetAndMissingStepsAreMade(): void {
		$paths = new DottedPath();

		$this->assertSame(
			expected: ['responses' => [['comment' => 'nieuw']], 'title' => 'Melding'],
			actual: $paths->withValueAt(
				data: ['responses' => [['comment' => 'oud']], 'title' => 'Melding'],
				path: 'responses.0.comment',
				value: 'nieuw'
			)
		);
		$this->assertSame(
			expected: ['title' => 'Melding', 'address' => ['street' => 'Dorpsstraat']],
			actual: $paths->withValueAt(data: ['title' => 'Melding'], path: 'address.street', value: 'Dorpsstraat')
		);
		$this->assertSame(
			expected: ['address' => ['street' => 'Dorpsstraat']],
			actual: $paths->withValueAt(data: ['address' => 'loose'], path: 'address.street', value: 'Dorpsstraat')
		);
	}//end testAValueIsSetAndMissingStepsAreMade()
}//end class
