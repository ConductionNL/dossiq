<?php

/**
 * Unit tests for PackageSettingRules: a package's settings.json holds valid
 * config keys with scalar values, or names another app's schema by app,
 * register and slug.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageSettingRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 */
class PackageSettingRulesTest extends TestCase {

	private PackageSettingRules $rules;

	protected function setUp(): void {
		parent::setUp();
		$this->rules = new PackageSettingRules();
	}//end setUp()

	public function testScalarValuesUnderValidKeysPass(): void {
		$this->assertSame(
			[],
			$this->rules->errors(['case_schema' => 'zaak_schema', 'max_days' => 42, 'enabled' => false, 'rate' => 1.5, 'a' => ''])
		);
	}//end testScalarValuesUnderValidKeysPass()

	public function testAKeyMustBeLowercaseStartWithALetterAndFitSixtyFourCharacters(): void {
		$longest = 'a' . str_repeat('b', 63);
		$tooLong = $longest . 'c';

		$this->assertSame([], $this->rules->errors([$longest => 'x']));
		$this->assertSame(
			[
				"settings.json: 'Case_schema' is not a valid settings key (a-z, 0-9, _, at most 64).",
				"settings.json: '1st' is not a valid settings key (a-z, 0-9, _, at most 64).",
				"settings.json: 'case-schema' is not a valid settings key (a-z, 0-9, _, at most 64).",
				"settings.json: '{$tooLong}' is not a valid settings key (a-z, 0-9, _, at most 64).",
			],
			$this->rules->errors(['Case_schema' => 'x', '1st' => 'x', 'case-schema' => 'x', $tooLong => 'x'])
		);
	}//end testAKeyMustBeLowercaseStartWithALetterAndFitSixtyFourCharacters()

	public function testANonScalarThatIsNoSchemaReferenceIsRefused(): void {
		$sentence = static fn (string $key): string => "settings.json: '{$key}' must hold a string, number or boolean, or name a schema by app, register and slug.";

		$this->assertSame(
			[$sentence('list'), $sentence('empty'), $sentence('extra'), $sentence('nothing')],
			$this->rules->errors(
				[
					'list' => ['a', 'b'],
					'empty' => [],
					'extra' => ['schema' => ['app' => 'a', 'register' => 'r', 'slug' => 's'], 'more' => 1],
					'nothing' => null,
				]
			)
		);
	}//end testANonScalarThatIsNoSchemaReferenceIsRefused()

	public function testAReferenceNeedsAnAppARegisterAndASlug(): void {
		$this->assertSame(
			[],
			$this->rules->errors(['case_schema' => ['schema' => ['app' => 'openregister', 'register' => 'zgw', 'slug' => 'zaak']]])
		);
		$this->assertSame(
			["settings.json: 'case_schema' names a schema without an app, a register and a slug; it needs all three."],
			$this->rules->errors(['case_schema' => ['schema' => ['app' => 'openregister', 'register' => 'zgw', 'slug' => '  ']]])
		);
	}//end testAReferenceNeedsAnAppARegisterAndASlug()

	public function testAReferenceIsTrimmedAndTakesOneOrManyRegisters(): void {
		$this->assertSame(
			['app' => 'openregister', 'registers' => ['zgw'], 'slug' => 'zaak'],
			$this->rules->reference(['schema' => ['app' => ' openregister ', 'register' => ' zgw ', 'slug' => ' zaak ']])
		);
		$this->assertSame(
			['app' => 'openregister', 'registers' => ['zgw', 'brp'], 'slug' => 'zaak'],
			$this->rules->reference(['schema' => ['app' => 'openregister', 'register' => ['zgw', ' ', 7, ' brp'], 'slug' => 'zaak']])
		);
	}//end testAReferenceIsTrimmedAndTakesOneOrManyRegisters()

	public function testAnIncompleteOrMalformedReferenceResolvesToNull(): void {
		$this->assertNull($this->rules->reference(['schema' => ['app' => 'openregister', 'slug' => 'zaak']]));
		$this->assertNull($this->rules->reference(['schema' => ['app' => 'openregister', 'register' => ['', 3], 'slug' => 'zaak']]));
		$this->assertNull($this->rules->reference(['schema' => ['app' => 5, 'register' => 'zgw', 'slug' => 'zaak']]));
		$this->assertNull($this->rules->reference(['schema' => 'zaak_schema']));
		$this->assertNull($this->rules->reference('zaak_schema'));
	}//end testAnIncompleteOrMalformedReferenceResolvesToNull()

	public function testOnlyAnArrayWithTheSingleSchemaKeyIsAReference(): void {
		$this->assertTrue($this->rules->isReference(['schema' => 'anything']));
		$this->assertFalse($this->rules->isReference(['schema' => 'x', 'app' => 'y']));
		$this->assertFalse($this->rules->isReference(['Schema' => 'x']));
		$this->assertFalse($this->rules->isReference('schema'));
	}//end testOnlyAnArrayWithTheSingleSchemaKeyIsAReference()
}//end class
