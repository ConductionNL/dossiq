<?php

/**
 * Structural guard: dossiq sets no Postgres search_path and ships no schema-per-tenant pipeline.
 *
 * The pipeline set `search_path` to a tenant schema that nothing ever created,
 * so Postgres skipped it and every request resolved in `public` (dossiq#2470).
 * It logged a line and isolated nothing. Tenant isolation is OpenRegister's
 * organisation row filter. This test keeps the pipeline from coming back.
 *
 * Comment stripping mirrors gate 23's `_code_lines`: whole-line comments are
 * dropped, trailing comments are kept. So the test and the gate agree on what
 * counts as code.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenant-isolation-names-the-control-that-runs/specs/tenant-isolation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No search_path, and none of the five pipeline classes, anywhere under lib/.
 *
 * @coversNothing
 */
class NoSearchPathTenancyTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The five classes of the schema-per-tenant pipeline.
	 *
	 * @var array<int, string>
	 */
	private const DELETED_CLASSES = [
		'TenantIsolationMiddleware',
		'TenantSchemaProvisioner',
		'TenantProvisioningService',
		'TenantSeedService',
		'TenantWelcomeMailer',
	];

	/**
	 * The two register descriptors.
	 *
	 * @var array<int, string>
	 */
	private const DESCRIPTORS = [
		'lib/Settings/dossiq_register.json',
		'lib/Settings/dossiq_mock_register.json',
	];

	/**
	 * No code line under lib/ contains `search_path`.
	 *
	 * @return void
	 */
	public function testNoCodeLineUnderLibSetsASearchPath(): void {
		$hits = [];
		foreach ($this->phpFilesUnderLib() as $relative => $absolute) {
			foreach ($this->codeLines(path: $absolute) as $number => $line) {
				if (stripos($line, 'search_path') !== false) {
					$hits[] = $relative.':'.$number;
				}
			}
		}

		$this->assertSame([], $hits, 'a code line under lib/ names search_path (REQ-TIS-001)');
	}//end testNoCodeLineUnderLibSetsASearchPath()

	/**
	 * None of the five pipeline classes exists or is named under lib/.
	 *
	 * Comments count here: a docblock that names a deleted class sends the
	 * reader to code that is not there.
	 *
	 * @return void
	 */
	public function testNoneOfTheFiveDeletedClassesIsNamedUnderLib(): void {
		$hits = [];
		foreach ($this->phpFilesUnderLib() as $relative => $absolute) {
			$source = (string) file_get_contents($absolute);
			foreach (self::DELETED_CLASSES as $class) {
				if (basename($relative) === $class.'.php' || str_contains($source, $class) === true) {
					$hits[] = $relative.' names '.$class;
				}
			}
		}

		$this->assertSame([], $hits, 'a deleted pipeline class is still named under lib/ (REQ-TIS-001)');
	}//end testNoneOfTheFiveDeletedClassesIsNamedUnderLib()

	/**
	 * Neither register descriptor names a deleted pipeline class.
	 *
	 * @return void
	 */
	public function testNeitherDescriptorNamesADeletedPipelineClass(): void {
		$hits = [];
		foreach (self::DESCRIPTORS as $descriptor) {
			$source = file_get_contents(self::ROOT.'/'.$descriptor);
			$this->assertIsString($source, $descriptor.' must be readable');
			foreach (self::DELETED_CLASSES as $class) {
				if (str_contains($source, $class) === true) {
					$hits[] = $descriptor.' names '.$class;
				}
			}
		}

		$this->assertSame([], $hits, 'a register descriptor names a deleted pipeline class (REQ-TIS-001)');
	}//end testNeitherDescriptorNamesADeletedPipelineClass()

	/**
	 * Every PHP file under lib/, keyed by its path relative to the root.
	 *
	 * @return array<string, string>
	 */
	private function phpFilesUnderLib(): array {
		$files    = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/lib'));
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$absolute = $file->getPathname();
			$files[substr($absolute, strlen(self::ROOT) + 1)] = $absolute;
		}

		ksort($files);
		$this->assertNotEmpty($files, 'the scan found no PHP file under lib/, so it proves nothing');

		return $files;
	}//end phpFilesUnderLib()

	/**
	 * The file's lines with whole-line comments removed, keyed by line number.
	 *
	 * The same rules as gate 23's `_code_lines`: a line that opens with `//`,
	 * `*`, `/*` or `#` (but not `#[`) is a comment, and so is every line inside
	 * a block comment.
	 *
	 * @param string $path The file to read.
	 *
	 * @return array<int, string>
	 */
	private function codeLines(string $path): array {
		$lines   = [];
		$inBlock = false;
		foreach (file($path) ?: [] as $index => $raw) {
			$trimmed = ltrim($raw, " \t");
			if ($inBlock === true) {
				if (str_contains($trimmed, '*/') === true) {
					$inBlock = false;
				}

				continue;
			}

			if (str_starts_with($trimmed, '/*') === true) {
				$inBlock = str_contains($trimmed, '*/') === false;
				continue;
			}

			if (str_starts_with($trimmed, '//') === true || str_starts_with($trimmed, '*') === true) {
				continue;
			}

			if (str_starts_with($trimmed, '#') === true && str_starts_with($trimmed, '#[') === false) {
				continue;
			}

			$lines[$index + 1] = $raw;
		}//end foreach

		return $lines;
	}//end codeLines()
}//end class
