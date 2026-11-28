<?php

/**
 * Test helper that pins a unit's observable output to a recorded JSON file.
 *
 * A characterization test records what the code answers today and fails on
 * any later drift. Run with GOLDEN_RECORD=1 to (re)write the files; a run
 * in that mode compares nothing, so it is always followed by a plain run.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

trait MatchesGoldenFiles {

	/**
	 * Compare a value with its recorded golden file, or record it.
	 *
	 * @param string $name  The golden file name, without extension, under tests/Unit/golden.
	 * @param mixed  $value The observed value; it is compared as pretty-printed JSON.
	 *
	 * @return void
	 */
	protected function assertMatchesGolden(string $name, mixed $value): void {
		$path = dirname(__DIR__) . '/Unit/golden/' . $name . '.json';
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
		$json = json_encode($value, $flags) . "\n";

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname($path)) === false) {
				mkdir(dirname($path), 0775, true);
			}

			file_put_contents($path, $json);
			$this->addToAssertionCount(count: 1);
			return;
		}

		$this->assertFileExists(filename: $path, message: 'Golden file missing; record it with GOLDEN_RECORD=1.');
		$this->assertSame(expected: file_get_contents($path), actual: $json);
	}//end assertMatchesGolden()
}//end trait
