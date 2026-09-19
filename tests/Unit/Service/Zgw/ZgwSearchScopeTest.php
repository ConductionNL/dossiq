<?php

/**
 * ZgwSearchScope contract tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwSearchScope;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a scope OpenRegister's paginated search can actually use.
 *
 * The rule is not "looks like a reference" but "survives the `(int)` cast that
 * `ObjectService::buildSearchQuery()` applies". A slug and a uuid both name a
 * real register on the write side and both arrive at the mapper as `0`.
 */
final class ZgwSearchScopeTest extends TestCase {
	/**
	 * A numeric id, as a string or an int, is the only searchable reference.
	 *
	 * @return void
	 */
	public function testNumericIdsAreSearchable(): void {
		$scope = ZgwSearchScope::fromMapping(
			mappingConfig: ['sourceRegister' => '12', 'sourceSchema' => 34]
		);

		$this->assertNotNull($scope);
		$this->assertSame(12, $scope->register);
		$this->assertSame(34, $scope->schema);
	}//end testNumericIdsAreSearchable()

	/**
	 * Every reference that `buildSearchQuery()` would turn into `0`.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}> Register/schema pairs.
	 */
	public static function unsearchableReferences(): array {
		return [
			'register given as a slug' => ['dossiq', '34'],
			'schema given as a slug' => ['12', 'zaak'],
			'both given as slugs' => ['dossiq', 'zaak'],
			'schema uuid' => ['12', '6f1d2e40-0c1a-4f2e-9a3b-8d5c1e7f0a21'],
			'empty schema' => ['12', ''],
			'whitespace schema' => ['12', '   '],
			'missing schema' => ['12', null],
			'zero schema' => ['12', '0'],
			'negative register' => [-1, '34'],
			'schema id with a numeric prefix' => ['12', '34-zaak'],
		];
	}//end unsearchableReferences()

	/**
	 * A reference the search cannot resolve yields no scope at all.
	 *
	 * @param mixed $register The stored register reference.
	 * @param mixed $schema The stored schema reference.
	 *
	 * @return void
	 *
	 * @dataProvider unsearchableReferences
	 */
	public function testUnsearchableReferencesYieldNoScope(mixed $register, mixed $schema): void {
		$this->assertNull(
			ZgwSearchScope::fromMapping(
				mappingConfig: ['sourceRegister' => $register, 'sourceSchema' => $schema]
			)
		);
	}//end testUnsearchableReferencesYieldNoScope()

	/**
	 * No mapping at all is not a searchable scope either.
	 *
	 * @return void
	 */
	public function testNullMappingIsNotSearchable(): void {
		$this->assertFalse(ZgwSearchScope::isSearchable(mappingConfig: null));
		$this->assertFalse(ZgwSearchScope::isSearchable(mappingConfig: []));
	}//end testNullMappingIsNotSearchable()
}//end class
