<?php

/**
 * How the BRK and WOZ response mappers coerce raw register values.
 *
 * Both mappers turn the same loose JSON shapes into the same typed DTO
 * values: a non-empty string or null, an int or null, a list of strings.
 * This test pins that coercion on both of them, field by field, so the two
 * cannot drift apart when they share one implementation.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External;

use OCA\Dossiq\Service\External\Brk\BrkResponseMapper;
use OCA\Dossiq\Service\External\Woz\WozResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\Brk\BrkResponseMapper
 * @covers \OCA\Dossiq\Service\External\Woz\WozResponseMapper
 * @uses \OCA\Dossiq\Service\External\RegisterValues
 */
class RegisterValueCoercionTest extends TestCase {

	/**
	 * Raw values and what each coercion makes of them.
	 *
	 * @return array<string, array{0: mixed, 1: string|null, 2: int|null, 3: array<int, string>}>
	 */
	public static function rawValues(): array {
		return [
			'non-empty string' => ['12', '12', 12, ['12']],
			'non-numeric string' => ['A', 'A', null, ['A']],
			'empty string' => ['', null, null, []],
			'int' => [7, '7', 7, []],
			'float' => [7.9, '7.9', 7, []],
			'null' => [null, null, null, []],
			'bool' => [true, null, null, []],
			'list' => [['a', '', 3, 'b'], null, null, ['a', 'b']],
		];
	}//end rawValues()

	/**
	 * BRK: `sectie` is a string, `perceelnummer` an int, `soortCultuurBebouwd` a list.
	 *
	 * @param mixed             $raw       The raw value.
	 * @param string|null       $asString  Its string coercion.
	 * @param int|null          $asInt     Its int coercion.
	 * @param array<int,string> $asStrings Its string-list coercion.
	 *
	 * @return void
	 *
	 * @dataProvider rawValues
	 */
	public function testTheBrkMapperCoercesEachValue(mixed $raw, ?string $asString, ?int $asInt, array $asStrings): void {
		$dto = (new BrkResponseMapper())->map(['sectie' => $raw, 'perceelnummer' => $raw, 'soortCultuurBebouwd' => $raw]);

		self::assertSame($asString, $dto['sectie']);
		self::assertSame($asInt, $dto['perceelnummer']);
		self::assertSame($asStrings, $dto['soortCultuurBebouwd']);
	}//end testTheBrkMapperCoercesEachValue()

	/**
	 * WOZ: `wozobjectnummer` is a string, `grondoppervlakte` an int, `gebruiksdoel` a list.
	 *
	 * @param mixed             $raw       The raw value.
	 * @param string|null       $asString  Its string coercion.
	 * @param int|null          $asInt     Its int coercion.
	 * @param array<int,string> $asStrings Its string-list coercion.
	 *
	 * @return void
	 *
	 * @dataProvider rawValues
	 */
	public function testTheWozMapperCoercesEachValue(mixed $raw, ?string $asString, ?int $asInt, array $asStrings): void {
		$dto = (new WozResponseMapper())->map(['wozobjectnummer' => $raw, 'grondoppervlakte' => $raw, 'gebruiksdoel' => $raw]);

		self::assertSame($asString, $dto['wozobjectnummer']);
		self::assertSame($asInt, $dto['grondoppervlakte']);
		self::assertSame($asStrings, $dto['gebruiksdoel']);
	}//end testTheWozMapperCoercesEachValue()

	/**
	 * Both map a list in order and skip what is not a fragment.
	 *
	 * @return void
	 */
	public function testBothMapManySkipNonFragments(): void {
		$brk = (new BrkResponseMapper())->mapMany([['sectie' => 'A'], 'x', ['sectie' => 'B']]);
		$woz = (new WozResponseMapper())->mapMany([['wozobjectnummer' => '1'], 3, ['wozobjectnummer' => '2']]);

		self::assertSame(['A', 'B'], array_column($brk, 'sectie'));
		self::assertSame(['1', '2'], array_column($woz, 'wozobjectnummer'));
	}//end testBothMapManySkipNonFragments()
}//end class
