<?php

/**
 * The money code is named for what it does, not for the penalty procedure.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No class file under lib/ names the procedure, and the procedure-named
 * config keys are read only through the legacy map.
 */
final class MoneyCodeNamesNoProcedureTest extends TestCase {
	/**
	 * Every PHP file under lib/, relative path to contents.
	 *
	 * @return array<string, string>
	 */
	private static function libFiles(): array {
		$root = dirname(__DIR__, 3) . '/lib';
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}

		return $files;
	}//end libFiles()

	/**
	 * No class file is named after the penalty procedure.
	 *
	 * @return void
	 */
	public function testNoClassFileNamesThePenaltyProcedure(): void {
		$named = array_values(array_filter(array_keys(self::libFiles()), static fn (string $path): bool => stripos(basename($path), 'dwangsom') !== false));

		self::assertSame(expected: [], actual: $named);
	}//end testNoClassFileNamesThePenaltyProcedure()

	/**
	 * The procedure-named setting keys are read only through the legacy map.
	 *
	 * @return void
	 */
	public function testTheProcedureKeysAreReadOnlyThroughTheLegacyMap(): void {
		$legacy = array_values(MoneyConfigKeys::LEGACY_KEYS);
		$readers = [];
		foreach (self::libFiles() as $path => $source) {
			if ($path === 'Service/Money/MoneyConfigKeys.php' || str_starts_with($path, 'Repair/') === true) {
				continue;
			}

			foreach ($legacy as $key) {
				if (str_contains($source, "'" . $key . "'") === true) {
					$readers[] = $path . ': ' . $key;
				}
			}
		}

		self::assertSame(expected: [], actual: $readers);
	}//end testTheProcedureKeysAreReadOnlyThroughTheLegacyMap()
}//end class
