<?php

/**
 * Every case definition package the app ships is one the installer accepts.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;
use OCA\Dossiq\Service\CaseDefinition\PackageIds;
use OCA\Dossiq\Service\CaseDefinition\PackageSeedRules;
use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sweeps `lib/Settings/packages/`.
 *
 * A package the validator refuses is refused on every install and upgrade,
 * and says so only in the log of an instance nobody is watching. The real
 * directory reader and the real validator read each shipped package here, so
 * that refusal is a red test instead.
 *
 * @coversNothing
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class ShippedPackagesAreValidTest extends TestCase {

	/**
	 * Where the shipped packages live.
	 */
	private const ROOT = __DIR__ . '/../../../lib/Settings/packages';

	/**
	 * Every shipped package passes the installer's own validation.
	 *
	 * @return void
	 */
	public function testEveryShippedPackageIsValid(): void {
		$directory = new PackageDirectory(root: self::ROOT);
		$validator = new PackageValidator(new NullLogger(), new PackageIds());

		$ids = $directory->ids();
		self::assertNotSame([], $ids, 'No package is shipped, so this sweep would pass on anything.');

		$errors = [];
		foreach ($ids as $id) {
			$package = $directory->read(id: $id);
			self::assertIsArray($package, "Package '{$id}' could not be read.");

			$result = $validator->validateShipped(package: $package);
			foreach ($result['errors'] as $error) {
				$errors[] = $id . ': ' . $error;
			}
		}

		self::assertSame([], $errors, implode("\n", $errors));
	}//end testEveryShippedPackageIsValid()

	/**
	 * A seed file that recognises its row by a flag alone ships one row.
	 *
	 * Matching on a flag means "the one that has it": the active default, the
	 * current version. Two rows in one file would both be that one, and on an
	 * instance that already holds it both would be adopted as the same row.
	 *
	 * @return void
	 */
	public function testASeedFileMatchingOnAFlagAloneShipsOneRow(): void {
		$rules = new PackageSeedRules();
		$wrong = [];
		foreach ((array)glob(self::ROOT . '/*/seed/*.json') as $path) {
			$seed = json_decode((string)file_get_contents((string)$path), true);
			self::assertIsArray($seed, $path . ' must parse');

			$properties = $rules->matchProperties(match: ($seed['match'] ?? null));
			$rows = array_values((array)($seed['rows'] ?? []));
			if (count($properties) !== 1 || $rows === [] || is_bool($rows[0][$properties[0]] ?? null) === false) {
				continue;
			}

			if (count($rows) !== 1) {
				$wrong[] = basename(dirname((string)$path, 2)) . '/seed/' . basename((string)$path)
					. ' matches on the flag ' . $properties[0] . ' and ships ' . count($rows) . ' rows';
			}
		}

		self::assertSame([], $wrong, implode("\n", $wrong));
	}//end testASeedFileMatchingOnAFlagAloneShipsOneRow()
}//end class
