<?php

/**
 * A grant case type says it runs the grant chain
 *
 * The flag is a case type property (decision 182), off by default, and the
 * subsidie package turns it on, so the case page can place the grant parts by
 * configuration rather than by the case type's name or folder.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Checks the grantChain flag on the case type schema and in the subsidie package.
 */
class GrantChainFlagTest extends TestCase {

	/**
	 * The repository root.
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * One file of the repository, decoded.
	 *
	 * @param string $path The path under the repository root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(self::ROOT . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The highest caseType version any register fragment declares, other than the given one.
	 *
	 * @param string $except The fragment to leave out.
	 *
	 * @return string
	 */
	private static function highestOtherCaseTypeVersion(string $except): string {
		$highest = '0.0.0';
		foreach ((array)glob(self::ROOT . '/lib/Settings/register.d/*.json') as $file) {
			if (basename((string)$file) === $except) {
				continue;
			}

			$version = (json_decode((string)file_get_contents((string)$file), true)['components']['schemas']['caseType']['version'] ?? null);
			if (is_string($version) === true && version_compare($version, $highest, '>') === true) {
				$highest = $version;
			}
		}

		return $highest;
	}//end highestOtherCaseTypeVersion()

	/**
	 * The case type schema carries the flag as a boolean that is off by default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.3
	 */
	public function testTheCaseTypeDeclaresTheFlagOffByDefault(): void {
		$caseType = (array)self::json(path: 'lib/Settings/register.d/99-case-type-grant-chain.json')['components']['schemas']['caseType'];

		$this->assertSame(expected: ['grantChain'], actual: array_keys((array)$caseType['properties']));
		$this->assertSame(expected: 'boolean', actual: $caseType['properties']['grantChain']['type']);
		$this->assertFalse(condition: $caseType['properties']['grantChain']['default']);
	}//end testTheCaseTypeDeclaresTheFlagOffByDefault()

	/**
	 * The fragment raises the caseType version above every other fragment, so an existing instance re-imports it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.3
	 */
	public function testTheFragmentRaisesTheCaseTypeVersion(): void {
		$version = (string)self::json(path: 'lib/Settings/register.d/99-case-type-grant-chain.json')['components']['schemas']['caseType']['version'];

		$this->assertTrue(
			condition: version_compare($version, self::highestOtherCaseTypeVersion(except: '99-case-type-grant-chain.json'), '>'),
			message: 'caseType ' . $version . ' must be above every other fragment'
		);
	}//end testTheFragmentRaisesTheCaseTypeVersion()

	/**
	 * The subsidie package turns the flag on and carries a new version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.3
	 */
	public function testThePackageRunsItsCaseTypeOnTheGrantChain(): void {
		$caseType = (array)self::json(path: 'lib/Settings/packages/subsidie/case-type.json')['caseType'];

		$this->assertTrue(condition: $caseType['grantChain']);
		$this->assertSame(expected: '1.9.0', actual: self::json(path: 'lib/Settings/packages/subsidie/package.json')['version']);
	}//end testThePackageRunsItsCaseTypeOnTheGrantChain()

	/**
	 * No other package claims the grant chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.3
	 */
	public function testOnlyTheSubsidiePackageSetsTheFlag(): void {
		$setters = [];
		foreach ((array)glob(self::ROOT . '/lib/Settings/packages/*/case-type.json') as $file) {
			$caseType = (json_decode((string)file_get_contents((string)$file), true)['caseType'] ?? []);
			if (($caseType['grantChain'] ?? false) === true) {
				$setters[] = basename(dirname((string)$file));
			}
		}

		$this->assertSame(expected: ['subsidie'], actual: $setters);
	}//end testOnlyTheSubsidiePackageSetsTheFlag()
}//end class
