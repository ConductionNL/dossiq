<?php

/**
 * No uuid save in lib/ hands OpenRegister a handful of fields.
 *
 * `saveObject()` with a uuid replaces the stored object with its payload.
 * A payload built from one or two fields therefore does not update them: it
 * drops every other property, and OpenRegister refuses it for the required
 * properties it no longer carries. The document status change, the metadata
 * edit, the file-id stamp, consultation and disposition decisions, the
 * subsidy transitions and the bezwaar audit trail all had this shape, and
 * all reported through fakes that accepted any payload.
 *
 * This guard reads every uuid save in lib/ and fails on a payload that is a
 * literal array, or a variable the same method starts from a literal array.
 * A partial write goes through `patchObjectAsArray()` instead, which merges
 * onto the stored object. It cannot see a payload that arrives as a method
 * parameter; those are covered by the behaviour tests beside it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static guard over every uuid save in lib/.
 *
 * @coversNothing
 */
class PartialSaveGuardTest extends TestCase {

	/**
	 * Uuid saves whose literal payload is deliberate, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		// Same defect, second one on top: `case.activity` is a JSON-encoded
		// string property (JsonEncodedStringProperties), and this writes a PHP
		// array into it, which the schema refuses however the merge is done.
		// Fixing it means deciding the activity shape; left for that decision.
		'lib/Service/ContactMomentService.php recordActivity()' => 'writes an array into a JSON-encoded string property',
	];

	/**
	 * Every uuid save in lib/ sends a whole object or goes through the PATCH seam.
	 *
	 * @return void
	 */
	public function testNoUuidSaveSendsAPartialPayload(): void {
		$offenders = [];
		foreach ($this->phpFiles() as $file) {
			foreach ($this->partialUuidSaves(file: $file) as $site) {
				if (isset(self::ALLOWED[$site]) === false) {
					$offenders[] = $site;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"These saves hand OpenRegister a partial object with a uuid, which replaces the stored object.\n"
			. 'Write them through patchObjectAsArray() instead.'
		);

	}//end testNoUuidSaveSendsAPartialPayload()

	/**
	 * The guard recognises the shape it exists to refuse.
	 *
	 * Without this, a regex that silently matched nothing would keep the
	 * guard green forever.
	 *
	 * @return void
	 */
	public function testTheGuardSeesALiteralAndABuiltPayload(): void {
		$source = <<<'PHP'
		<?php
		class Sample {
			public function literal($os) {
				$os->saveObject(object: ['status' => 'x'], register: 'r', schema: 's', uuid: 'u');
			}
			public function built($os) {
				$patch = ['status' => 'x'];
				$patch['closedAt'] = 'now';
				return $this->saveObjectAsArray(
					objectService: $os,
					register: 'r',
					schema: 's',
					object: $patch,
					uuid: 'u'
				);
			}
			public function whole($os, array $stored) {
				$merged = array_merge($stored, ['status' => 'x']);
				$os->saveObject(object: $merged, register: 'r', schema: 's', uuid: 'u');
			}
			public function create($os) {
				$os->saveObject(object: ['status' => 'x'], register: 'r', schema: 's');
			}
		}
		PHP;
		$file = tempnam(sys_get_temp_dir(), 'guard');
		file_put_contents($file, $source);

		$sites = $this->partialUuidSaves(file: $file);
		unlink($file);

		$this->assertCount(2, $sites, implode(', ', $sites));
		$this->assertStringContainsString('literal()', $sites[0]);
		$this->assertStringContainsString('built()', $sites[1]);

	}//end testTheGuardSeesALiteralAndABuiltPayload()

	/**
	 * The partial uuid saves in one file.
	 *
	 * @param string $file The PHP file.
	 *
	 * @return array<int, string> `path method()` per offending save.
	 */
	private function partialUuidSaves(string $file): array {
		$lines = explode("\n", (string)file_get_contents($file));
		$sites = [];
		foreach ($lines as $index => $line) {
			if (preg_match('/->(saveObject|saveObjectAsArray)\(/', $line) !== 1) {
				continue;
			}

			$call = implode("\n", array_slice($lines, $index, 14));
			if (preg_match('/(?:saveObject|saveObjectAsArray)\((.*?)\)\s*(?:;|\?\?|\))/s', $call, $match) !== 1) {
				continue;
			}

			$args = $match[1];
			if (preg_match('/\buuid\s*:\s*null\b/', $args) === 1 || preg_match('/\buuid\s*:/', $args) !== 1) {
				continue;
			}

			if (preg_match('/\bobject\s*:\s*(\[|\$\w+)/', $args, $payload) !== 1) {
				continue;
			}

			[$method, $body] = $this->enclosingMethod(lines: $lines, index: $index);
			$partial = ($payload[1] === '[');
			if ($partial === false) {
				$variable = preg_quote($payload[1], '/');
				$first = preg_match('/' . $variable . '\s*=\s*(\S)/', $body, $assigned);
				$partial = ($first === 1 && $assigned[1] === '[');
			}

			if ($partial === true) {
				$sites[] = $this->relative(file: $file) . ' ' . $method . '()';
			}
		}//end foreach

		return $sites;

	}//end partialUuidSaves()

	/**
	 * The name and body (up to the call) of the method a line sits in.
	 *
	 * @param array<int, string> $lines The file's lines.
	 * @param int                $index The line of the call.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function enclosingMethod(array $lines, int $index): array {
		for ($start = $index; $start >= 0; $start--) {
			if (preg_match('/function\s+(\w+)\s*\(/', $lines[$start], $name) === 1) {
				return [$name[1], implode("\n", array_slice($lines, $start, ($index - $start)))];
			}
		}

		return ['?', ''];

	}//end enclosingMethod()

	/**
	 * Every PHP file under lib/.
	 *
	 * @return array<int, string>
	 */
	private function phpFiles(): array {
		$root = dirname(__DIR__, 4) . '/lib';
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$files[] = $entry->getPathname();
			}
		}

		sort($files);

		return $files;

	}//end phpFiles()

	/**
	 * A path relative to the app root, for readable failures.
	 *
	 * @param string $file The absolute path.
	 *
	 * @return string
	 */
	private function relative(string $file): string {
		$root = dirname(__DIR__, 4) . '/';

		return str_starts_with($file, $root) === true ? substr($file, strlen($root)) : basename($file);

	}//end relative()
}//end class
