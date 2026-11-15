<?php

/**
 * DecodesPackageFiles — a package file as a JSON object, or the reason it is not one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\DecodesPackageFiles;
use PHPUnit\Framework\TestCase;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\DecodesPackageFiles
 */
final class DecodesPackageFilesTest extends TestCase {

	/**
	 * An object decodes; a missing file, a list and broken JSON are recorded.
	 *
	 * @return void
	 */
	public function testDecode(): void {
		$host = new class {
			use DecodesPackageFiles;

			/**
			 * The errors found.
			 *
			 * @var array<int, string>
			 */
			public array $errors = [];

			/**
			 * Expose the decoder.
			 *
			 * @param array<string, string> $files The files.
			 * @param string                $path  The file.
			 *
			 * @return array<string, mixed>|null
			 */
			public function read(array $files, string $path): ?array {
				return $this->decode(files: $files, path: $path);
			}//end read()
		};
		$files = ['a.json' => '{"x": 1}', 'list.json' => '[1, 2]', 'broken.json' => '{'];

		self::assertSame(['x' => 1], $host->read(files: $files, path: 'a.json'));
		self::assertNull($host->read(files: $files, path: 'gone.json'));
		self::assertNull($host->read(files: $files, path: 'list.json'));
		self::assertNull($host->read(files: $files, path: 'broken.json'));
		self::assertSame('gone.json is missing.', $host->errors[0]);
		self::assertCount(3, $host->errors);
	}//end testDecode()
}//end class
