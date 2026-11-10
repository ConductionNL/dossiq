<?php

/**
 * BrondatumArchiefValidator Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\BrondatumArchiefValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The brondatumArchiefprocedure cross-field table, error by error and in order.
 *
 * @covers \OCA\Dossiq\Service\Zgw\BrondatumArchiefValidator
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrondatumArchiefValidatorTest extends TestCase {
	use MakesZrcRuleGroup;

	/**
	 * Archive values, the selectielijst answer, and every error in order as [name, code, reason].
	 *
	 * @return array<string, array{0: array, 1: array|null, 2: array}>
	 */
	public static function archives(): array {
		$p = 'brondatumArchiefprocedure.';
		return [
			'afgehandeld, nothing set' => [['afleidingswijze' => 'afgehandeld'], null, []],
			'empty archive' => [[], null, []],
			'eigenschap without datumkenmerk' => [
				['afleidingswijze' => 'eigenschap'],
				null,
				[[$p . 'datumkenmerk', 'required', $p . 'datumkenmerk is vereist voor afleidingswijze "eigenschap".']],
			],
			'afgehandeld with every field set' => [
				[
					'afleidingswijze' => 'afgehandeld',
					'datumkenmerk' => 'x',
					'einddatumBekend' => 'true',
					'objecttype' => 'o',
					'registratie' => 'r',
					'procestermijn' => 'P1Y',
				],
				null,
				[
					[$p . 'datumkenmerk', 'must-be-empty', $p . 'datumkenmerk mag niet ingevuld zijn voor afleidingswijze "afgehandeld".'],
					[$p . 'einddatumBekend', 'must-be-empty', 'einddatumBekend moet false zijn voor afleidingswijze "afgehandeld".'],
					[$p . 'objecttype', 'must-be-empty', $p . 'objecttype mag niet ingevuld zijn voor afleidingswijze "afgehandeld".'],
					[$p . 'registratie', 'must-be-empty', $p . 'registratie mag niet ingevuld zijn voor afleidingswijze "afgehandeld".'],
					[$p . 'procestermijn', 'must-be-empty', $p . 'procestermijn mag niet ingevuld zijn voor afleidingswijze "afgehandeld".'],
				],
			],
			'ander_datumkenmerk missing everything' => [
				['afleidingswijze' => 'ander_datumkenmerk', 'einddatumBekend' => true],
				['procestermijn' => 'nihil'],
				[
					[$p . 'datumkenmerk', 'required', $p . 'datumkenmerk is vereist voor afleidingswijze "ander_datumkenmerk".'],
					[$p . 'objecttype', 'required', $p . 'objecttype is vereist voor afleidingswijze "ander_datumkenmerk".'],
					[$p . 'registratie', 'required', $p . 'registratie is vereist voor afleidingswijze "ander_datumkenmerk".'],
					['nonFieldErrors', 'invalid-afleidingswijze-for-procestermijn', 'Afleidingswijze "ander_datumkenmerk" is niet geldig bij selectielijstklasse met procestermijn "nihil".'],
				],
			],
			'zaakobject complete' => [
				['afleidingswijze' => 'zaakobject', 'datumkenmerk' => 'd', 'objecttype' => 'o', 'einddatumBekend' => false],
				['procestermijn' => 'nihil'],
				[['nonFieldErrors', 'invalid-afleidingswijze-for-procestermijn', 'Afleidingswijze "zaakobject" is niet geldig bij selectielijstklasse met procestermijn "nihil".']],
			],
			'termijn with a non-string procestermijn' => [
				['afleidingswijze' => 'termijn', 'procestermijn' => ['P1Y'], 'einddatumBekend' => true],
				['procestermijn' => ''],
				[
					[$p . 'einddatumBekend', 'must-be-empty', 'einddatumBekend moet false zijn voor afleidingswijze "termijn".'],
					[$p . 'procestermijn', 'required', $p . 'procestermijn is vereist voor afleidingswijze "termijn".'],
					[$p . 'procestermijn', 'required', $p . 'procestermijn is vereist voor afleidingswijze "termijn" maar selectielijstklasse heeft geen procestermijn.'],
				],
			],
			'termijn complete, selectielijst without procestermijn' => [
				['afleidingswijze' => 'termijn', 'procestermijn' => 'P5Y'],
				[],
				[[$p . 'procestermijn', 'required', $p . 'procestermijn is vereist voor afleidingswijze "termijn" maar selectielijstklasse heeft geen procestermijn.']],
			],
			'afgehandeld against bestaansduur' => [
				['afleidingswijze' => 'afgehandeld', 'einddatumBekend' => 1],
				['procestermijn' => 'bestaansduur_procesobject'],
				[['nonFieldErrors', 'invalid-afleidingswijze-for-procestermijn', 'Afleidingswijze "afgehandeld" is niet geldig bij selectielijstklasse met procestermijn "bestaansduur_procesobject".']],
			],
			'termijn against bestaansduur' => [
				['afleidingswijze' => 'termijn', 'procestermijn' => 'P5Y'],
				['procestermijn' => 'bestaansduur_procesobject'],
				[],
			],
			'afgehandeld against nihil' => [
				['afleidingswijze' => 'afgehandeld'],
				['procestermijn' => 'nihil'],
				[],
			],
		];
	}//end archives()

	/**
	 * Every error, in the order the table checks them.
	 *
	 * @param array $archive The brondatumArchiefprocedure value.
	 * @param array|null $selectielijst The selectielijstklasse answer.
	 * @param array $expected The errors as [name, code, reason].
	 *
	 * @return void
	 */
	#[DataProvider('archives')]
	public function testEveryErrorInOrder(array $archive, ?array $selectielijst, array $expected): void {
		$validator = $this->ruleGroup(class: BrondatumArchiefValidator::class, objects: null);

		$errors = $validator->validate($archive, $selectielijst);

		$this->assertSame(
			array_map(static fn (array $e): array => ['name' => $e[0], 'code' => $e[1], 'reason' => $e[2]], $expected),
			$errors
		);
	}//end testEveryErrorInOrder()

	/**
	 * A non-string field value is refused by the field check's string type.
	 *
	 * @return void
	 */
	public function testANonStringFieldValueIsATypeError(): void {
		$validator = $this->ruleGroup(class: BrondatumArchiefValidator::class, objects: null);

		$this->expectException(\TypeError::class);
		$validator->validate(['afleidingswijze' => 'eigenschap', 'datumkenmerk' => 5], null);
	}//end testANonStringFieldValueIsATypeError()
}//end class
