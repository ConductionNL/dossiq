<?php

/**
 * Scanner for declared display flags nothing honours.
 *
 * A display flag is a boolean an administrator sets to change what other
 * people see. `statusType.hiddenInLists` is the archetype: one checkbox on one
 * settings form, and the promise that cases in that status leave the working
 * list.
 *
 * 🔴 IT FOLLOWS THE DECLARED CALCULATION, NOT THE NAME. A grep for
 * `hiddenInLists` finds a form field, a default and four seed rows, and reads
 * as a control nobody honours. It is not: the case carries the same answer
 * under a DIFFERENT NAME, `statusHiddenInLists`, declared in the case schema's
 * `x-openregister-calculations` as `@ref.statusType.hiddenInLists`, and it is
 * that name the Cases index filters on. A scanner that matched names would
 * have called the flag dark and been wrong, which is exactly the mistake this
 * change's proposal records against itself.
 *
 * So a flag has a reader when something reads it, OR reads a property the
 * register declares as calculated from it. The calculation graph is read out
 * of the register, never restated here, because a second copy of it would
 * eventually disagree with the one OpenRegister evaluates.
 *
 * 🔑 THE AUTHORING SURFACE IS NOT A READER, and this is the whole reason the
 * scan is worth running. The form that SETS a flag mentions it by definition;
 * counting that as a reader would make every flag pass the moment somebody
 * added the checkbox, which is the state `hiddenInLists` was actually in. A
 * reader is something that HONOURS the flag, so the settings views and the
 * form-shape utilities are excluded from the scan by path.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the display flags a schema declares and decides which ones are read.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
final class DisplayFlagReaderScanner {

	/**
	 * The vocabulary that makes a boolean a DISPLAY flag.
	 *
	 * Deliberately a name match, and only on the DECLARATION side. Deciding
	 * which booleans are display controls is a question about intent that only
	 * the name answers; deciding whether one is READ is a question about code,
	 * and that half never matches names.
	 *
	 * @var string
	 */
	private const DISPLAY_VOCABULARY = '/hidden|visible|hide|show|display|collaps|pinned|featured|highlight/i';

	/**
	 * Paths whose mention of a flag is authoring, not honouring.
	 *
	 * @var list<string>
	 */
	private const AUTHORING_PATHS = [
		'/src/views/settings/',
		'/src/utils/statusTypeForm.js',
		'/src/utils/caseTypeForm.js',
	];

	/**
	 * File extensions worth scanning for a reader.
	 *
	 * @var list<string>
	 */
	private const READABLE = ['php', 'js', 'vue', 'json', 'ts'];

	/**
	 * Constructor.
	 *
	 * @param string $registerFile Absolute path to the register definition.
	 * @param list<string> $sourceDirs Absolute paths of the roots a reader may live in.
	 */
	public function __construct(
		private readonly string $registerFile,
		private readonly array $sourceDirs,
	) {
	}//end __construct()

	/**
	 * Every display flag the register declares, as `schema.property`.
	 *
	 * @return list<string> The declared flags, sorted.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function declaredFlags(): array {
		$flags = [];
		foreach ($this->schemas() as $schemaName => $schema) {
			foreach ((array)($schema['properties'] ?? []) as $property => $definition) {
				if (is_array($definition) === false) {
					continue;
				}

				if (($definition['type'] ?? '') !== 'boolean') {
					continue;
				}

				if (preg_match(self::DISPLAY_VOCABULARY, (string)$property) !== 1) {
					continue;
				}

				$flags[] = $schemaName.'.'.$property;
			}
		}

		sort($flags);

		return $flags;
	}//end declaredFlags()

	/**
	 * The names that answer for one flag: the flag itself, plus every property
	 * the register declares as calculated from it.
	 *
	 * The walk is transitive, so a mirror of a mirror still counts. It is also
	 * bounded by the number of calculations, because each name is added once.
	 *
	 * @param string $flag The flag, as `schema.property`.
	 *
	 * @return list<string> The property names that stand for this flag.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function namesFor(string $flag): array {
		$property = (string)(explode('.', $flag)[1] ?? '');
		if ($property === '') {
			return [];
		}

		$names = [$property];
		$calculations = $this->calculations();
		$changed = true;
		while ($changed === true) {
			$changed = false;
			foreach ($calculations as $calculated => $sources) {
				if (in_array($calculated, $names, true) === true) {
					continue;
				}

				if (array_intersect($sources, $names) === []) {
					continue;
				}

				$names[] = $calculated;
				$changed = true;
			}
		}

		return $names;
	}//end namesFor()

	/**
	 * The files that honour one flag, by any of its names.
	 *
	 * @param string $flag The flag, as `schema.property`.
	 *
	 * @return list<string> The repo-relative paths that read it, sorted.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function readersOf(string $flag): array {
		$names = $this->namesFor(flag: $flag);
		if ($names === []) {
			return [];
		}

		$readers = [];
		foreach ($this->sourceFiles() as $file) {
			$body = (string)file_get_contents($file);
			foreach ($names as $name) {
				if (str_contains($body, $name) === false) {
					continue;
				}

				$readers[] = $file;
				break;
			}
		}

		sort($readers);

		return $readers;
	}//end readersOf()

	/**
	 * The register's schema map.
	 *
	 * @return array<string, array<string, mixed>> The schemas by name.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents($this->registerFile), true);
		if (is_array($register) === false) {
			return [];
		}

		$schemas = ($register['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return [];
		}

		return $schemas;
	}//end schemas()

	/**
	 * Every declared calculation, as calculated-property to source properties.
	 *
	 * The sources are pulled out of the expression by matching `@ref.<schema>.`
	 * paths and bare `prop` references, because an expression is a nested
	 * structure whose shape OpenRegister owns and this scanner should not
	 * reimplement. Over-reading a source is safe here: it can only give a flag
	 * MORE names to be read under, and a flag with a reader is the passing
	 * case.
	 *
	 * @return array<string, list<string>> Calculated property to its sources.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	private function calculations(): array {
		$map = [];
		foreach ($this->schemas() as $schema) {
			$declared = ($schema['configuration']['x-openregister-calculations'] ?? []);
			if (is_array($declared) === false) {
				continue;
			}

			foreach ($declared as $calculated => $definition) {
				$encoded = json_encode($definition);
				$sources = [];
				if (preg_match_all('/"@ref\.[A-Za-z0-9_]+\.([A-Za-z0-9_]+)"/', (string)$encoded, $matches) > 0) {
					$sources = $matches[1];
				}

				$map[(string)$calculated] = array_values(array_unique($sources));
			}
		}

		return $map;
	}//end calculations()

	/**
	 * Every file a reader could live in.
	 *
	 * @return list<string> Absolute paths, authoring surfaces excluded.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	private function sourceFiles(): array {
		$files = [];
		foreach ($this->sourceDirs as $dir) {
			if (is_dir($dir) === false) {
				continue;
			}

			$walker = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($walker as $entry) {
				$path = str_replace('\\', '/', (string)$entry->getPathname());
				if ($entry->isFile() === false) {
					continue;
				}

				if (in_array(strtolower((string)$entry->getExtension()), self::READABLE, true) === false) {
					continue;
				}

				if ($this->isAuthoring(path: $path) === true) {
					continue;
				}

				if (str_contains($path, '/lib/Settings/') === true) {
					// The register and its seed rows DECLARE the flag. A
					// declaration reading itself would make every flag pass.
					continue;
				}

				$files[] = $path;
			}
		}

		return $files;
	}//end sourceFiles()

	/**
	 * Whether a path is an authoring surface rather than a reader.
	 *
	 * @param string $path The forward-slashed absolute path.
	 *
	 * @return bool True when a mention there is authoring.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	private function isAuthoring(string $path): bool {
		foreach (self::AUTHORING_PATHS as $fragment) {
			if (str_contains($path, $fragment) === true) {
				return true;
			}
		}

		return false;
	}//end isAuthoring()
}//end class
