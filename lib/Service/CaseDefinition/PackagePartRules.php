<?php

/**
 * Dossiq package part rules.
 *
 * What each part file of a shipped package has to look like, and that every
 * row carries a fixed id used once in the package. Split from
 * {@see ShippedPackageRules}, which checks the manifest and asks this class
 * about the parts it declares.
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
 * Checks the part files of a shipped package.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackagePartRules {

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
	 * Check the files of every declared part.
	 *
	 * @param array<int, string>    $parts The declared parts.
	 * @param array<string, string> $files The package files.
	 *
	 * @return array<int, string> The errors, in the order found.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function check(array $parts, array $files): array {
		$this->errors = [];
		$this->seen = [];
		foreach ($parts as $part) {
			$this->checkPart(part: $part, files: $files);
		}

		return $this->errors;
	}//end check()

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
			'seed' => $this->checkSeed(files: $files, dir: 'seed/'),
			'examples' => $this->checkSeed(files: $files, dir: 'examples/'),
			'settings' => $this->checkSettings(files: $files),
			default => null,
		};
	}//end checkPart()

	/**
	 * Check `case-type.json`.
	 *
	 * `caseType` is one object, or a list of objects when a package carries a
	 * family of case types that move together (bezwaar and beroep). Each
	 * child row names its own case type, so the list needs no other change.
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

		if ($this->isCaseTypeShape(value: ($data['caseType'] ?? null)) === false) {
			$this->errors[] = 'case-type.json: caseType must be one object or a non-empty list of objects.';
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
	 * Whether `caseType` is one object or a non-empty list of objects.
	 *
	 * @param mixed $value The decoded `caseType` value.
	 *
	 * @return bool
	 */
	private function isCaseTypeShape(mixed $value): bool {
		if (is_array($value) === false || $value === []) {
			return false;
		}

		if (array_is_list($value) === false) {
			return true;
		}

		foreach ($value as $caseType) {
			if (is_array($caseType) === false || $caseType === [] || array_is_list($caseType) === true) {
				return false;
			}
		}

		return true;
	}//end isCaseTypeShape()

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
	 * Check the `seed/` or `examples/` files: each names the schema its rows
	 * go to, by settings key for a schema of this app, or by app, register and
	 * slug for a schema of another app. A file may name the property (`match`)
	 * an existing row is recognised by; then every row carries it. Example
	 * files follow the same rules as seed files.
	 *
	 * @param array<string, string> $files The package files.
	 * @param string                $dir   The part's directory, `seed/` or `examples/`.
	 *
	 * @return void
	 */
	private function checkSeed(array $files, string $dir): void {
		$paths = $this->jsonUnder(files: $files, dir: $dir);
		if ($paths === []) {
			$this->errors[] = "{$dir}: the part is declared but holds no .json file.";
		}

		foreach ($paths as $path) {
			$seed = $this->decode(files: $files, path: $path);
			if ($seed === null) {
				continue;
			}

			array_push($this->errors, ...(new PackageSeedRules())->errors(seed: $seed, path: $path));

			$rows = ($seed['rows'] ?? null);
			if (is_array($rows) === false || $rows === [] || array_is_list($rows) === false) {
				$this->errors[] = "{$path}: rows must be a non-empty list.";
				continue;
			}

			$this->checkRows(rows: $rows, where: $path);
		}
	}//end checkSeed()

	/**
	 * Check `settings.json`: flat keys an app config can hold, scalar values,
	 * or a value that names another app's schema by app, register and slug.
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

		array_push($this->errors, ...(new PackageSettingRules())->errors(settings: $settings));
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
}//end class
