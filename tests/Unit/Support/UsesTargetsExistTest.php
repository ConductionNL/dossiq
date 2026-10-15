<?php

/**
 * Every coverage `uses` target names a class this app still has.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * `phpunit.xml` sets `beStrictAboutCoverageMetadata`, so PHPUnit refuses an
 * `uses` annotation whose target does not exist: every test carrying one turns
 * risky, and `failOnRisky` turns that into a red suite. A rename breaks it. The
 * class moves, its callers and imports move with it, and the annotation in a
 * docblock is the one reference no compiler, IDE or static analyser follows.
 *
 * Measured on 2026-10-15: three renames in one day each left a dangling
 * annotation behind (`Service\Termijn\CaseNumberReader`, `Woo\WooWrittenCase`,
 * `Service\IntakeConfirmation`), and each was found by a CI run that had
 * already cost half an hour. The check below costs milliseconds and names the
 * file and the target.
 *
 * It needs no coverage driver, which is the point: the strict-metadata failure
 * only appears in the one CI cell that runs with coverage, so a developer
 * cannot reproduce it locally.
 */
class UsesTargetsExistTest extends TestCase {

	/**
	 * Every `uses` target in the suite resolves to a file.
	 *
	 * This docblock spells the annotation without its at-sign on purpose: PHPUnit
	 * reads one anywhere in a docblock, so naming it here would make this very
	 * test the thing it refuses.
	 *
	 * @return void
	 */
	public function testEveryUsesTargetResolvesToAClassThisAppHas(): void {
		$root = dirname(__DIR__, 3);
		$dangling = [];

		foreach ($this->testFiles(directory: $root.'/tests') as $file) {
			$source = (string)file_get_contents($file);
			preg_match_all('/@uses\s+\\\\(OCA\\\\Dossiq\\\\[A-Za-z0-9_\\\\]+)/', $source, $matches);
			foreach ($matches[1] as $target) {
				if (file_exists($root.'/'.self::pathFor(target: $target)) === false) {
					$dangling[] = substr($file, (strlen($root) + 1)) . ' names ' . $target;
				}
			}
		}

		$this->assertSame(
			[],
			array_values(array_unique($dangling)),
			"An @uses names a class this app no longer has.\n"
			. "A rename moved or deleted it and left the annotation behind. Point the\n"
			. "annotation at the new name, or drop it when the class is gone."
		);

	}//end testEveryUsesTargetResolvesToAClassThisAppHas()

	/**
	 * The file a fully qualified app class is expected to live in.
	 *
	 * `OCA\Dossiq\Tests\…` lives under `tests/`, everything else under `lib/`.
	 *
	 * @param string $target The fully qualified class name, without a leading slash.
	 *
	 * @return string The path, relative to the app root.
	 */
	private static function pathFor(string $target): string {
		$rest = substr($target, strlen('OCA\\Dossiq\\'));
		if (str_starts_with($rest, 'Tests\\') === true) {
			return 'tests/'.str_replace('\\', '/', substr($rest, strlen('Tests\\'))).'.php';
		}

		return 'lib/'.str_replace('\\', '/', $rest).'.php';

	}//end pathFor()

	/**
	 * Every PHP file under a directory.
	 *
	 * @param string $directory Where to look.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function testFiles(string $directory): array {
		$found = [];
		$walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
		foreach ($walker as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$found[] = $entry->getPathname();
			}
		}

		return $found;

	}//end testFiles()
}//end class
