<?php

/**
 * Normalising one step's task block: names, forms and effects.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskDeclaration
 */
class TaskDeclarationTest extends TestCase {

	/**
	 * A step without a task block, or with a non-array one, keeps the defaults.
	 *
	 * @return void
	 */
	public function testMissingBlockIsTheDefault(): void {
		$declaration = new TaskDeclaration();

		$this->assertSame(expected: TaskDeclaration::NONE, actual: $declaration->forStep(step: ['title' => 'A']));
		$this->assertSame(expected: TaskDeclaration::NONE, actual: $declaration->forStep(step: ['task' => 'on']));
	}//end testMissingBlockIsTheDefault()

	/**
	 * Malformed values collapse to their empty forms and a negative lead time to zero.
	 *
	 * @return void
	 */
	public function testMalformedValuesCollapse(): void {
		$result = (new TaskDeclaration())->forStep(
			step: [
				'task' => [
					'leadTimeDays' => -4,
					'candidateGroups' => 'Juridisch',
					'candidateUsers' => [42, ['nested'], null, ' anna ', 'anna'],
					'form' => [],
					'effects' => 'sendEmail',
				],
			]
		);

		$this->assertSame(
			expected: [
				'enabled' => true,
				'leadTimeDays' => 0,
				'candidateGroups' => [],
				'candidateUsers' => ['42', 'anna'],
				'form' => null,
				'effects' => [],
			],
			actual: $result
		);
	}//end testMalformedValuesCollapse()

	/**
	 * Effects without a type, or that are not arrays, are dropped; types are trimmed.
	 *
	 * @return void
	 */
	public function testEffectsAreFilteredAndTrimmed(): void {
		$result = (new TaskDeclaration())->forStep(
			step: ['task' => ['effects' => ['x', ['type' => '  '], ['type' => ' sendEmail ', 'template' => 't']]]]
		);

		$this->assertSame(expected: [['type' => 'sendEmail', 'template' => 't']], actual: $result['effects']);
	}//end testEffectsAreFilteredAndTrimmed()

	/**
	 * Title and status are trimmed strings, empty when absent.
	 *
	 * @return void
	 */
	public function testTitleAndStatusAreTrimmed(): void {
		$declaration = new TaskDeclaration();

		$this->assertSame(expected: 'Toets', actual: $declaration->titleOf(step: ['title' => ' Toets ']));
		$this->assertSame(expected: '', actual: $declaration->titleOf(step: []));
		$this->assertSame(expected: 's-1', actual: $declaration->statusOf(step: ['status' => "s-1\n"]));
		$this->assertSame(expected: '', actual: $declaration->statusOf(step: []));
	}//end testTitleAndStatusAreTrimmed()
}//end class
