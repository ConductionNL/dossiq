<?php

/**
 * StartableFlowLookup unit tests.
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

use OCA\Dossiq\Service\Flow\StartableFlowLookup;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for StartableFlowLookup.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * @covers \OCA\Dossiq\Service\Flow\StartableFlowLookup
 */
class StartableFlowLookupTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$value = new StartableFlowLookup(
			objectService: $objectService,
			register: 'dossiq',
			typeSchema: 'caseType'
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: 'dossiq', actual: $value->register);
		$this->assertSame(expected: 'caseType', actual: $value->typeSchema);
	}//end testPropertiesReadBack()

	/**
	 * A plain uuid and the object shape a picker writes are both read; blanks and a non-list are not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testFlowIdsOfReadsPlainAndPickerEntries(): void {
		$lookup = $this->lookup();

		$this->assertSame(
			expected: ['flow-a', 'flow-b'],
			actual: $lookup->flowIdsOf(declared: [' flow-a ', ['id' => 'flow-b'], '', ['title' => 'no id'], '  '])
		);
		$this->assertSame(expected: [], actual: $lookup->flowIdsOf(declared: 'flow-a'));
		$this->assertSame(expected: [], actual: $lookup->flowIdsOf(declared: null));
	}//end testFlowIdsOfReadsPlainAndPickerEntries()

	/**
	 * Null stays null, an array stays itself, an entity reads through jsonSerialize, else its properties.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAsArrayReadsEveryAnswerShape(): void {
		$lookup = $this->lookup();

		$entity = new class implements JsonSerializable {
			/**
			 * The serialised entity.
			 *
			 * @return array<string, string> The fields.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-1'];
			}//end jsonSerialize()
		};

		$scalar = new class implements JsonSerializable {
			/**
			 * A property read when the serialisation is not a list.
			 *
			 * @var string
			 */
			public string $id = 'case-2';

			/**
			 * A serialisation that is not an array.
			 *
			 * @return string The scalar.
			 */
			public function jsonSerialize(): string {
				return 'case-2';
			}//end jsonSerialize()
		};

		$plain = new stdClass();
		$plain->id = 'case-3';

		$this->assertNull(actual: $lookup->asArray(found: null));
		$this->assertSame(expected: ['id' => 'case-0'], actual: $lookup->asArray(found: ['id' => 'case-0']));
		$this->assertSame(expected: ['id' => 'case-1'], actual: $lookup->asArray(found: $entity));
		$this->assertSame(expected: ['id' => 'case-2'], actual: $lookup->asArray(found: $scalar));
		$this->assertSame(expected: ['id' => 'case-3'], actual: $lookup->asArray(found: $plain));
	}//end testAsArrayReadsEveryAnswerShape()

	/**
	 * A lookup over a placeholder ObjectService.
	 *
	 * @return StartableFlowLookup The lookup.
	 */
	private function lookup(): StartableFlowLookup {
		return new StartableFlowLookup(objectService: new stdClass(), register: 'dossiq', typeSchema: 'caseType');
	}//end lookup()
}//end class
