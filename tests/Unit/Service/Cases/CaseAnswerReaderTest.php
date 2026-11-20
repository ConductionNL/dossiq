<?php

/**
 * Unit tests for CaseAnswerReader.
 *
 * A case's answers arrive in two shapes: the register's list of
 * `{propertyDefinition, name, value}` rows, and a legacy name-keyed map. Both
 * must read as the same answers, and an answer without a name or a value must
 * not be read at all, because a rebind would otherwise list it as dropped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseAnswerReader;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseAnswerReader.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseAnswerReader
 *
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Cases\RebindValueConverter
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class CaseAnswerReaderTest extends TestCase {

	use BuildsRebindConverter;

	/**
	 * The reader over the real store id helpers and the real converter.
	 *
	 * @return CaseAnswerReader The reader.
	 */
	private function reader(): CaseAnswerReader {
		return new CaseAnswerReader(
			store: new CaseTypeStore($this->createMock(SettingsService::class)),
			converter: $this->rebindConverter(),
		);
	}//end reader()

	/**
	 * The register's list shape reads name, definition id and value as text.
	 *
	 * @return void
	 */
	public function testTheListShapeReadsNameDefinitionAndValue(): void {
		$entries = $this->reader()->entriesOf(case: [
			'properties' => [
				['name' => ' boomsoort ', 'propertyDefinition' => 'pd-1', 'value' => ' eik '],
				['name' => 'oppervlakte', 'propertyDefinition' => ['id' => 'pd-2'], 'value' => 120],
				['name' => 'gekapt', 'propertyDefinition' => ['@self' => ['id' => 'pd-3']], 'value' => true],
			],
		]);

		self::assertSame(
			[
				['name' => 'boomsoort', 'definition' => 'pd-1', 'value' => 'eik'],
				['name' => 'oppervlakte', 'definition' => 'pd-2', 'value' => '120'],
				['name' => 'gekapt', 'definition' => 'pd-3', 'value' => 'true'],
			],
			$entries
		);
	}//end testTheListShapeReadsNameDefinitionAndValue()

	/**
	 * A legacy name-keyed map reads the key as the name, with no definition.
	 *
	 * @return void
	 */
	public function testTheLegacyMapReadsTheKeyAsTheName(): void {
		$entries = $this->reader()->entriesOf(case: [
			'properties' => [' Boomsoort ' => 'eik', 'gekapt' => false, 'tags' => ['a', 'b']],
		]);

		self::assertSame(
			[
				['name' => 'Boomsoort', 'definition' => '', 'value' => 'eik'],
				['name' => 'gekapt', 'definition' => '', 'value' => 'false'],
				['name' => 'tags', 'definition' => '', 'value' => '["a","b"]'],
			],
			$entries
		);
	}//end testTheLegacyMapReadsTheKeyAsTheName()

	/**
	 * Answers stored as a JSON string decode before they are read.
	 *
	 * @return void
	 */
	public function testAJsonStringOfAnswersIsDecoded(): void {
		$entries = $this->reader()->entriesOf(case: [
			'properties' => '[{"name":"boomsoort","propertyDefinition":"pd-1","value":"eik"}]',
		]);

		self::assertSame([['name' => 'boomsoort', 'definition' => 'pd-1', 'value' => 'eik']], $entries);
	}//end testAJsonStringOfAnswersIsDecoded()

	/**
	 * No answers, unreadable answers and a non-array all read as none.
	 *
	 * @return void
	 */
	public function testAbsentOrUnreadableAnswersReadAsNone(): void {
		$reader = $this->reader();

		self::assertSame([], $reader->entriesOf(case: []));
		self::assertSame([], $reader->entriesOf(case: ['properties' => 'not json']));
		self::assertSame([], $reader->entriesOf(case: ['properties' => 42]));
	}//end testAbsentOrUnreadableAnswersReadAsNone()

	/**
	 * An answer without a name or without a value is left out.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutANameOrAValueIsLeftOut(): void {
		$entries = $this->reader()->entriesOf(case: [
			'properties' => [
				['name' => '', 'propertyDefinition' => 'pd-1', 'value' => 'eik'],
				['name' => 'leeg', 'propertyDefinition' => 'pd-2', 'value' => null],
				['name' => 'spaties', 'propertyDefinition' => 'pd-3', 'value' => '   '],
				['name' => 'lijst', 'propertyDefinition' => 'pd-4', 'value' => []],
				['name' => 'blijft', 'propertyDefinition' => 'pd-5', 'value' => '0'],
			],
		]);

		self::assertSame([['name' => 'blijft', 'definition' => 'pd-5', 'value' => '0']], $entries);
	}//end testAnAnswerWithoutANameOrAValueIsLeftOut()

	/**
	 * A list position whose item is not an array is read as a legacy map entry.
	 *
	 * @return void
	 */
	public function testAScalarAtAListPositionFallsBackToTheMapShape(): void {
		$entries = $this->reader()->entriesOf(case: ['properties' => ['eik']]);

		self::assertSame([['name' => '0', 'definition' => '', 'value' => 'eik']], $entries);
	}//end testAScalarAtAListPositionFallsBackToTheMapShape()

	/**
	 * Keyed maps compare names trimmed and in lower case, dropping empty names.
	 *
	 * @return void
	 */
	public function testKeyedComparesNamesTrimmedAndLowerCase(): void {
		$reader = $this->reader();

		self::assertSame(
			['boomsoort' => 'eik', 'ë-straat' => 'b', '5' => 'five'],
			$reader->keyed(values: [' BoomSoort ' => 'eik', 'Ë-STRAAT' => 'b', '  ' => 'dropped', 5 => 'five'])
		);
		self::assertSame('ëik', $reader->key(name: '  ËIK '));
	}//end testKeyedComparesNamesTrimmedAndLowerCase()
}//end class
