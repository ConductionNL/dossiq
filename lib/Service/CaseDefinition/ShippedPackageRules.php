<?php

/**
 * Dossiq shipped package rules.
 *
 * The structural rules a package on disk has to meet before anything reads it
 * as configuration: what the manifest says, which parts exist, what each part
 * file looks like, and that every row carries a stable id. Split from
 * {@see PackageValidator}, which also validates the ZIP export format and
 * would otherwise carry two unrelated rule sets.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Checks a shipped package's manifest and part files.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class ShippedPackageRules {

	/**
	 * Each part the installer writes, and the capability it stands for.
	 *
	 * @var array<string, string>
	 */
	public const PART_CAPABILITY = [
		'case-type' => 'case-type',
		'flows' => 'flow',
		'seed' => 'seed',
		'settings' => 'settings',
	];

	/**
	 * The collections a `case-type.json` may carry besides `caseType`.
	 *
	 * @var array<int, string>
	 */
	public const CASE_TYPE_COLLECTIONS = [
		'caseType',
		'propertyDefinitions',
		'statusTypes',
		'roleTypes',
		'documentTypes',
		'resultTypes',
	];

	/**
	 * How a package installs: on every instance, or when an administrator asks.
	 *
	 * @var array<int, string>
	 */
	public const INSTALL_MODES = ['auto', 'on-request'];

	/**
	 * What a row id looks like. A fixed UUID is what makes a second install
	 * recognise the row instead of writing a twin.
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Errors found so far.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Row ids seen so far, to refuse one id used twice.
	 *
	 * @var array<string, string>
	 */
	private array $seen = [];

	/**
	 * Check one package.
	 *
	 * @param array{id: string, files: array<string, string>} $package The package.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>}
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function check(array $package): array {
		$this->errors = [];
		$this->seen = [];
		$files = $package['files'];

		$manifest = $this->decode(files: $files, path: PackageDirectory::MANIFEST);
		if ($manifest === null) {
			return $this->result(manifest: null, warnings: []);
		}

		$this->checkManifest(manifest: $manifest, dirId: $package['id']);

		$parts = array_values(array_filter((array)($manifest['parts'] ?? []), 'is_string'));
		foreach ($parts as $part) {
			$this->checkPart(part: $part, files: $files);
		}

		return $this->result(manifest: $manifest, warnings: $this->strayFiles(files: $files, parts: $parts));
	}//end check()

	/**
	 * Check the manifest's own fields.
	 *
	 * @param array<string, mixed> $manifest The decoded manifest.
	 * @param string               $dirId    The directory the package sits in.
	 *
	 * @return void
	 */
	private function checkManifest(array $manifest, string $dirId): void {
		if (($manifest['id'] ?? null) !== $dirId) {
			$this->errors[] = "package.json: id must be '{$dirId}', the directory it sits in.";
		}

		if (is_string($manifest['title'] ?? null) === false || trim($manifest['title']) === '') {
			$this->errors[] = 'package.json: title is missing.';
		}

		if (preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) !== 1) {
			$this->errors[] = 'package.json: version must read major.minor.patch.';
		}

		$requires = ($manifest['requires'] ?? null);
		if (is_array($requires) === false || array_is_list($requires) === false
			|| count(array_filter($requires, 'is_string')) !== count($requires)
		) {
			$this->errors[] = 'package.json: requires must be a list of capability names.';
		}

		$this->checkParts(parts: ($manifest['parts'] ?? null));

		$install = ($manifest['install'] ?? 'auto');
		if (in_array($install, self::INSTALL_MODES, true) === false) {
			$this->errors[] = "package.json: install must be 'auto' or 'on-request'.";
		}
	}//end checkManifest()

	/**
	 * Check the manifest's part list.
	 *
	 * @param mixed $parts The `parts` value.
	 *
	 * @return void
	 */
	private function checkParts(mixed $parts): void {
		if (is_array($parts) === false || $parts === [] || array_is_list($parts) === false) {
			$this->errors[] = 'package.json: parts must be a non-empty list.';
			return;
		}

		foreach ($parts as $part) {
			if (is_string($part) === false || isset(self::PART_CAPABILITY[$part]) === false) {
				$this->errors[] = "package.json: part '" . $this->describe(value: $part) . "' is not one this installer can write.";
			}
		}
	}//end checkParts()

	/**
	 * A value as it reads in a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string, or the type of anything else.
	 */
	private function describe(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return gettype($value);
	}//end describe()

	/**
	 * Check the files of one declared part.
	 *
	 * @param string                $part  The part name.
	 * @param array<string, string> $files The package files.
	 *
	 * @return void
	 */
	private function checkPart(string $part, array $files): void {
		match ($part) {
			'case-type' => $this->checkCaseType(files: $files),
			'flows' => $this->checkRowFiles(files: $files, dir: 'flows/'),
			'seed' => $this->checkSeed(files: $files),
			'settings' => $this->checkSettings(files: $files),
			default => null,
		};
	}//end checkPart()

	/**
	 * Check `case-type.json`.
	 *
	 * @param array<string, string> $files The package files.
	 *
	 * @return void
	 */
	private function checkCaseType(array $files): void {
		$data = $this->decode(files: $files, path: 'case-type.json');
		if ($data === null) {
			return;
		}

		if (is_array($data['caseType'] ?? null) === false || array_is_list($data['caseType']) === true) {
			$this->errors[] = 'case-type.json: caseType must be one object.';
		}

		foreach ($data as $collection => $rows) {
			if (in_array($collection, self::CASE_TYPE_COLLECTIONS, true) === false) {
				$this->errors[] = "case-type.json: '{$collection}' is not a case type collection.";
				continue;
			}

			$this->checkRows(rows: $this->listOf(value: $rows), where: "case-type.json {$collection}");
		}
	}//end checkCaseType()

	/**
	 * Check a directory of files that are one row each.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $dir   The directory prefix.
	 *
	 * @return void
	 */
	private function checkRowFiles(array $files, string $dir): void {
		$paths = $this->jsonUnder(files: $files, dir: $dir);
		if ($paths === []) {
			$this->errors[] = "{$dir}: the part is declared but holds no .json file.";
		}

		foreach ($paths as $path) {
			$row = $this->decode(files: $files, path: $path);
			if ($row !== null) {
				$this->checkRows(rows: [$row], where: $path);
			}
		}
	}//end checkRowFiles()

	/**
	 * Check the `seed/` files: each names the schema key its rows go to.
	 *
	 * @param array<string, string> $files The package files.
	 *
	 * @return void
	 */
	private function checkSeed(array $files): void {
		$paths = $this->jsonUnder(files: $files, dir: 'seed/');
		if ($paths === []) {
			$this->errors[] = 'seed/: the part is declared but holds no .json file.';
		}

		foreach ($paths as $path) {
			$seed = $this->decode(files: $files, path: $path);
			if ($seed === null) {
				continue;
			}

			if (preg_match('/^[a-z][a-z0-9_]*_schema$/', (string)($seed['schema'] ?? '')) !== 1) {
				$this->errors[] = "{$path}: schema must name a settings key ending in _schema.";
			}

			$rows = ($seed['rows'] ?? null);
			if (is_array($rows) === false || $rows === [] || array_is_list($rows) === false) {
				$this->errors[] = "{$path}: rows must be a non-empty list.";
				continue;
			}

			$this->checkRows(rows: $rows, where: $path);
		}
	}//end checkSeed()

	/**
	 * Check `settings.json`: flat keys an app config can hold, scalar values.
	 *
	 * @param array<string, string> $files The package files.
	 *
	 * @return void
	 */
	private function checkSettings(array $files): void {
		$settings = $this->decode(files: $files, path: 'settings.json');
		if ($settings === null) {
			return;
		}

		foreach ($settings as $key => $value) {
			// 64 is the app config key limit: a longer key is refused by the
			// store at write time, after the rows went in.
			if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string)$key) !== 1) {
				$this->errors[] = "settings.json: '{$key}' is not a valid settings key (a-z, 0-9, _, at most 64).";
			}

			if (is_scalar($value) === false) {
				$this->errors[] = "settings.json: '{$key}' must hold a string, number or boolean.";
			}
		}
	}//end checkSettings()

	/**
	 * Check that every row is an object with a UUID id nobody else in the package uses.
	 *
	 * @param array<int|string, mixed> $rows  The rows.
	 * @param string                   $where Where they sit, for the message.
	 *
	 * @return void
	 */
	private function checkRows(array $rows, string $where): void {
		foreach ($rows as $index => $row) {
			$id = '';
			if (is_array($row) === true) {
				$id = (string)($row['id'] ?? '');
			}

			if (preg_match(self::UUID, $id) !== 1) {
				$this->errors[] = "{$where} row {$index}: id must be a fixed UUID, so a second install recognises it.";
				continue;
			}

			if (isset($this->seen[$id]) === true) {
				$this->errors[] = "{$where} row {$index}: id {$id} is also used in {$this->seen[$id]}.";
				continue;
			}

			$this->seen[$id] = $where;
		}
	}//end checkRows()

	/**
	 * Files that belong to no declared part, as warnings.
	 *
	 * @param array<string, string> $files The package files.
	 * @param array<int, string>    $parts The declared parts.
	 *
	 * @return array<int, string> One warning per stray file.
	 */
	private function strayFiles(array $files, array $parts): array {
		$owned = [PackageDirectory::MANIFEST => true, 'README.md' => true];
		$prefixes = [];
		foreach ($parts as $part) {
			match ($part) {
				'case-type' => $owned['case-type.json'] = true,
				'settings' => $owned['settings.json'] = true,
				'flows' => $prefixes[] = 'flows/',
				'seed' => $prefixes[] = 'seed/',
				default => null,
			};
		}

		$warnings = [];
		foreach (array_keys($files) as $path) {
			if (isset($owned[$path]) === true || $this->startsWithAny(path: $path, prefixes: $prefixes) === true) {
				continue;
			}

			$warnings[] = "{$path} belongs to no declared part and is not installed.";
		}

		return $warnings;
	}//end strayFiles()

	/**
	 * Whether a path starts with one of the prefixes.
	 *
	 * @param string             $path     The path.
	 * @param array<int, string> $prefixes The prefixes.
	 *
	 * @return boolean True on a match.
	 */
	private function startsWithAny(string $path, array $prefixes): bool {
		foreach ($prefixes as $prefix) {
			if (str_starts_with($path, $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end startsWithAny()

	/**
	 * The .json files directly under a directory, sorted.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $dir   The directory prefix.
	 *
	 * @return array<int, string> The paths.
	 */
	private function jsonUnder(array $files, string $dir): array {
		$paths = array_values(
			array_filter(
				array_keys($files),
				static fn (string $path): bool => str_starts_with($path, $dir) === true && str_ends_with($path, '.json') === true
			)
		);
		sort($paths);

		return $paths;
	}//end jsonUnder()

	/**
	 * A collection value as a list of rows: one object is wrapped.
	 *
	 * @param mixed $value The collection value.
	 *
	 * @return array<int|string, mixed> The rows.
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [$value];
		}

		if ($value !== [] && array_is_list($value) === false) {
			return [$value];
		}

		return $value;
	}//end listOf()

	/**
	 * Decode one file to an object, recording why when it cannot be.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $path  The file.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function decode(array $files, string $path): ?array {
		if (isset($files[$path]) === false) {
			$this->errors[] = "{$path} is missing.";
			return null;
		}

		$data = json_decode($files[$path], true);
		if (is_array($data) === false || ($data !== [] && array_is_list($data) === true)) {
			$this->errors[] = "{$path} is not a JSON object: " . json_last_error_msg();
			return null;
		}

		return $data;
	}//end decode()

	/**
	 * The result in the validator's shape.
	 *
	 * @param array<string, mixed>|null $manifest The manifest, when it decoded.
	 * @param array<int, string>        $warnings The warnings.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>}
	 */
	private function result(?array $manifest, array $warnings): array {
		return [
			'valid' => ($this->errors === []),
			'errors' => $this->errors,
			'warnings' => $warnings,
			'manifest' => $manifest,
		];
	}//end result()
}//end class
