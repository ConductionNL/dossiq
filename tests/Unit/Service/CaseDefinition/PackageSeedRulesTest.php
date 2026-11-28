<?php

/**
 * Unit tests for PackageSeedRules.
 *
 * A seed file names the schema its rows go to and, optionally, the properties
 * an existing row is recognised by. A bad `schema` or `match` must be refused
 * at validation time with a message naming the file and row, because an
 * install that gets past it writes rows nobody can find again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageSeedRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PackageSeedRules.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageSeedRules
 *
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 */
class PackageSeedRulesTest extends TestCase {

	/**
	 * A settings key ending in _schema is a valid schema; nothing else as text is.
	 *
	 * @return void
	 */
	public function testASchemaByTextMustBeASettingsKeyEndingInSchema(): void {
		$rules = new PackageSeedRules();
		$wanted = 'seeds/a.json: schema must name a settings key ending in _schema, or another app\'s schema by app, register and slug.';

		self::assertSame([], $rules->errors(seed: ['schema' => 'status_type_schema'], path: 'seeds/a.json'));
		self::assertSame([$wanted], $rules->errors(seed: ['schema' => 'status_type'], path: 'seeds/a.json'));
		self::assertSame([$wanted], $rules->errors(seed: ['schema' => 'Status_schema'], path: 'seeds/a.json'));
		self::assertSame([$wanted], $rules->errors(seed: ['schema' => '_schema'], path: 'seeds/a.json'));
		self::assertSame([$wanted], $rules->errors(seed: [], path: 'seeds/a.json'));
		self::assertSame([$wanted], $rules->errors(seed: ['schema' => 42], path: 'seeds/a.json'));
	}//end testASchemaByTextMustBeASettingsKeyEndingInSchema()

	/**
	 * Another app's schema needs an app, a register and a slug.
	 *
	 * @return void
	 */
	public function testAnotherAppsSchemaNeedsAppRegisterAndSlug(): void {
		$rules = new PackageSeedRules();
		$wanted = "seeds/b.json: schema names another app's schema without an app, a register and a slug; it needs all three.";

		self::assertSame(
			[],
			$rules->errors(seed: ['schema' => ['app' => 'openregister', 'register' => 'publication', 'slug' => 'organisation']], path: 'seeds/b.json')
		);
		self::assertSame(
			[$wanted],
			$rules->errors(seed: ['schema' => ['app' => 'openregister', 'register' => 'publication']], path: 'seeds/b.json')
		);
		self::assertSame([$wanted], $rules->errors(seed: ['schema' => []], path: 'seeds/b.json'));
	}//end testAnotherAppsSchemaNeedsAppRegisterAndSlug()

	/**
	 * Match names one property or a list of them; duplicates collapse.
	 *
	 * @return void
	 */
	public function testMatchPropertiesReadsOneOrAList(): void {
		$rules = new PackageSeedRules();

		self::assertSame(['code'], $rules->matchProperties(match: 'code'));
		self::assertSame(['code', 'caseType'], $rules->matchProperties(match: ['code', 'caseType', 'code']));
	}//end testMatchPropertiesReadsOneOrAList()

	/**
	 * Anything that is not a property name, or a list of them, names nothing.
	 *
	 * @return void
	 */
	public function testAnInvalidMatchNamesNothing(): void {
		$rules = new PackageSeedRules();

		self::assertSame([], $rules->matchProperties(match: null));
		self::assertSame([], $rules->matchProperties(match: []));
		self::assertSame([], $rules->matchProperties(match: ['a' => 'code']));
		self::assertSame([], $rules->matchProperties(match: ['code', 5]));
		self::assertSame([], $rules->matchProperties(match: '1code'));
		self::assertSame([], $rules->matchProperties(match: 'co-de'));
		self::assertSame([], $rules->matchProperties(match: ['code', '']));
	}//end testAnInvalidMatchNamesNothing()

	/**
	 * Text that is not blank, an integer or a flag can recognise a row.
	 *
	 * @return void
	 */
	public function testWhatCanRecogniseARow(): void {
		$rules = new PackageSeedRules();

		self::assertTrue($rules->isMatchable(value: 'x'));
		self::assertTrue($rules->isMatchable(value: 0));
		self::assertTrue($rules->isMatchable(value: false));
		self::assertFalse($rules->isMatchable(value: '  '));
		self::assertFalse($rules->isMatchable(value: 1.5));
		self::assertFalse($rules->isMatchable(value: null));
		self::assertFalse($rules->isMatchable(value: ['x']));
	}//end testWhatCanRecogniseARow()

	/**
	 * An invalid match is one error, whatever the rows hold.
	 *
	 * @return void
	 */
	public function testAnInvalidMatchIsOneError(): void {
		$errors = (new PackageSeedRules())->errors(
			seed: ['schema' => 'status_type_schema', 'match' => 5, 'rows' => [['code' => 'a'], []]],
			path: 'seeds/c.json'
		);

		self::assertSame(['seeds/c.json: match must name one property of the rows, or a list of them.'], $errors);
	}//end testAnInvalidMatchIsOneError()

	/**
	 * Every row lacking a matchable value for a matched property is named.
	 *
	 * @return void
	 */
	public function testEachRowWithoutAMatchedValueIsNamed(): void {
		$errors = (new PackageSeedRules())->errors(
			seed: [
				'schema' => 'status_type_schema',
				'match' => ['code', 'caseType'],
				'rows' => [
					['code' => 'a', 'caseType' => 'ct-1'],
					['code' => '', 'caseType' => 'ct-1'],
					'not a row',
				],
			],
			path: 'seeds/d.json'
		);

		self::assertSame(
			[
				"seeds/d.json row 1: has no 'code', a property this file recognises an existing row by.",
				"seeds/d.json row 2: has no 'code', a property this file recognises an existing row by.",
				"seeds/d.json row 2: has no 'caseType', a property this file recognises an existing row by.",
			],
			$errors
		);
	}//end testEachRowWithoutAMatchedValueIsNamed()

	/**
	 * Match is only checked when present and the rows are a list; schema errors come first.
	 *
	 * @return void
	 */
	public function testMatchIsCheckedOnlyWithRowsAndAfterTheSchema(): void {
		$rules = new PackageSeedRules();

		self::assertSame([], $rules->errors(seed: ['schema' => 'status_type_schema', 'rows' => [[]]], path: 'p'));
		self::assertSame([], $rules->errors(seed: ['schema' => 'status_type_schema', 'match' => 5, 'rows' => 'x'], path: 'p'));
		self::assertSame(
			[
				'p: schema must name a settings key ending in _schema, or another app\'s schema by app, register and slug.',
				'p: match must name one property of the rows, or a list of them.',
			],
			$rules->errors(seed: ['match' => null, 'rows' => []], path: 'p')
		);
	}//end testMatchIsCheckedOnlyWithRowsAndAfterTheSchema()
}//end class
