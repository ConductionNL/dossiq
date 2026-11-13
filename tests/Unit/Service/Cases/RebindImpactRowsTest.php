<?php

/**
 * The rows of a rebind impact report: ported, dropped and required fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\RebindAnswer;
use OCA\Dossiq\Service\Cases\RebindImpactRows;
use OCA\Dossiq\Service\Cases\RebindLanding;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Cases\RebindImpactRows
 *
 * @uses \OCA\Dossiq\Service\Cases\RebindAnswer
 * @uses \OCA\Dossiq\Service\Cases\RebindLanding
 * @uses \OCA\Dossiq\Service\Cases\RebindValueConverter
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class RebindImpactRowsTest extends TestCase {

	use BuildsRebindConverter;

	/**
	 * The rows builder over the real converter and store.
	 *
	 * @return RebindImpactRows The builder.
	 */
	private function rows(): RebindImpactRows {
		return new RebindImpactRows(
			store: new CaseTypeStore(settingsService: $this->createMock(SettingsService::class)),
			converter: $this->rebindConverter()
		);
	}//end rows()

	/**
	 * A source answer named `amount` holding `$value`.
	 *
	 * @param string               $value  The answer's value.
	 * @param array<string, mixed> $source The source definition, [] for a free answer.
	 *
	 * @return RebindAnswer The answer.
	 */
	private function answer(string $value, array $source): RebindAnswer {
		return new RebindAnswer(
			key: 'k1',
			entry: ['name' => 'amount', 'definition' => 'src-def', 'value' => $value],
			source: $source
		);
	}//end answer()

	/**
	 * Same kind and value: mapping `same`, the target named and identified.
	 *
	 * @return void
	 */
	public function testAPortedRowWithTheSameKindAndValueIsSame(): void {
		$row = $this->rows()->portedRow(
			answer: $this->answer(value: '12', source: ['propertyType' => 'number']),
			landing: new RebindLanding(target: ['id' => 'tgt-def', 'name' => ' Bedrag ', 'propertyType' => 'number'], value: '12', remapped: false)
		);

		$this->assertSame(
			[
				'source' => 'amount',
				'target' => 'Bedrag',
				'targetDefinition' => 'tgt-def',
				'sourceKind' => 'number',
				'targetKind' => 'number',
				'value' => '12',
				'newValue' => '12',
				'mapping' => 'same',
			],
			$row
		);
	}//end testAPortedRowWithTheSameKindAndValueIsSame()

	/**
	 * A different kind, or a different value, makes the row `converted`.
	 *
	 * @return void
	 */
	public function testAChangedKindOrValueIsConverted(): void {
		$rows = $this->rows();

		$kind = $rows->portedRow(
			answer: $this->answer(value: '12', source: ['propertyType' => 'string']),
			landing: new RebindLanding(target: ['id' => 't', 'propertyType' => 'integer'], value: '12', remapped: false)
		);
		$value = $rows->portedRow(
			answer: $this->answer(value: 'yes', source: ['propertyType' => 'boolean']),
			landing: new RebindLanding(target: ['id' => 't', 'propertyType' => 'boolean'], value: 'true', remapped: false)
		);

		$this->assertSame(['text', 'integer', 'converted'], [$kind['sourceKind'], $kind['targetKind'], $kind['mapping']]);
		$this->assertSame(['boolean', 'boolean', 'converted'], [$value['sourceKind'], $value['targetKind'], $value['mapping']]);
	}//end testAChangedKindOrValueIsConverted()

	/**
	 * A remapped landing is `remapped`, even when nothing else changed.
	 *
	 * @return void
	 */
	public function testARemappedLandingWinsOverEverythingElse(): void {
		$row = $this->rows()->portedRow(
			answer: $this->answer(value: 'a', source: []),
			landing: new RebindLanding(target: ['@self' => ['id' => 'self-def']], value: 'a', remapped: true)
		);

		$this->assertSame('remapped', $row['mapping']);
		$this->assertSame('text', $row['sourceKind']);
		$this->assertSame('self-def', $row['targetDefinition']);
		$this->assertSame('', $row['target']);
	}//end testARemappedLandingWinsOverEverythingElse()

	/**
	 * A dropped row says `absent` without a target and `type` with an incompatible one.
	 *
	 * @return void
	 */
	public function testADroppedRowNamesWhyItWasDropped(): void {
		$rows = $this->rows();
		$answer = $this->answer(value: 'red', source: ['enumValues' => ['red', 'blue']]);

		$this->assertSame(
			['name' => 'amount', 'definition' => 'src-def', 'kind' => 'choice', 'value' => 'red', 'reason' => 'absent', 'candidates' => []],
			$rows->droppedRow(answer: $answer, target: [])
		);
		$this->assertSame('type', $rows->droppedRow(answer: $answer, target: ['propertyType' => 'object'])['reason']);
		$this->assertSame('text', $rows->droppedRow(answer: $this->answer(value: 'x', source: []), target: [])['kind']);
	}//end testADroppedRowNamesWhyItWasDropped()

	/**
	 * A required row carries the target's choices and whether the value fits.
	 *
	 * @return void
	 */
	public function testARequiredRowValidatesTheValueAgainstTheTarget(): void {
		$rows = $this->rows();
		$target = ['uuid' => 'req-def', 'name' => ' Kleur ', 'description' => ' Pick one ', 'enumValues' => ['red', 'blue', '', 7]];

		$this->assertSame(
			[
				'name' => 'Kleur',
				'definition' => 'req-def',
				'kind' => 'choice',
				'choices' => ['red', 'blue', '7'],
				'description' => 'Pick one',
				'value' => 'blue',
				'normalized' => 'blue',
				'valid' => true,
			],
			$rows->requiredRow(target: $target, value: 'blue')
		);

		$invalid = $rows->requiredRow(target: $target, value: 'green');
		$this->assertNull($invalid['normalized']);
		$this->assertFalse($invalid['valid']);

		$number = $rows->requiredRow(target: ['propertyType' => 'integer'], value: ' 42 ');
		$this->assertSame(['integer', '42', true, ''], [$number['kind'], $number['normalized'], $number['valid'], $number['definition']]);
	}//end testARequiredRowValidatesTheValueAgainstTheTarget()
}//end class
