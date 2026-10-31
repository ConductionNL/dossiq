<?php

/**
 * Dossiq shipped package directory.
 *
 * Reads the case definition packages the app ships on disk, one directory per
 * package under `lib/Settings/packages/<package-id>/`, each with a
 * `package.json` manifest. It reads and decodes; it does not judge. Whether a
 * package is valid is {@see PackageValidator}'s question, and whether it may
 * be installed here is {@see PackageInstaller}'s.
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
 * Reads the packages the app ships from disk.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageDirectory {

	/**
	 * The manifest every package directory carries.
	 */
	public const MANIFEST = 'package.json';

	/**
	 * What a package id may look like: lower case, digits and hyphens.
	 *
	 * The id is a directory name, so this is also what keeps `../` out of a
	 * path built from it.
	 */
	public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

	/**
	 * Constructor.
	 *
	 * @param string|null $root The directory that holds the packages, or null
	 *                          for the ones the app ships.
	 */
	public function __construct(
		private readonly ?string $root = null,
	) {
	}//end __construct()

	/**
	 * A reader over another directory, for an administrator's or a test's packages.
	 *
	 * @param string $root The directory that holds the packages.
	 *
	 * @return self The reader.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function withRoot(string $root): self {
		return new self(root: $root);
	}//end withRoot()

	/**
	 * The directory the packages are read from.
	 *
	 * @return string The absolute path.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function root(): string {
		if ($this->root !== null && $this->root !== '') {
			return rtrim($this->root, '/');
		}

		return dirname(__DIR__, 2) . '/Settings/packages';
	}//end root()

	/**
	 * The id of every package directory that carries a manifest, sorted.
	 *
	 * @return array<int, string> The package ids.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function ids(): array {
		$root = $this->root();
		if (is_dir($root) === false) {
			return [];
		}

		$ids = [];
		foreach ((array)scandir($root) as $entry) {
			$entry = (string)$entry;
			if (preg_match(self::ID_PATTERN, $entry) !== 1) {
				continue;
			}

			if (is_file($root . '/' . $entry . '/' . self::MANIFEST) === true) {
				$ids[] = $entry;
			}
		}

		sort($ids);

		return $ids;
	}//end ids()

	/**
	 * Read one package: its manifest and every file it carries, raw, and a
	 * digest of those files by path, so a change to any of them is seen even
	 * at the same version.
	 *
	 * @param string $id The package id, which is its directory name.
	 *
	 * @return array{id: string, files: array<string, string>, digest: string}|null The package,
	 *         or null when no such package directory exists.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function read(string $id): ?array {
		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return null;
		}

		$dir = $this->root() . '/' . $id;
		if (is_dir($dir) === false) {
			return null;
		}

		$files = $this->filesUnder(dir: $dir, prefix: '');
		$digest = hash_init('sha256');
		foreach ($files as $path => $content) {
			hash_update($digest, $path . "\0" . $content . "\0");
		}

		return [
			'id' => $id,
			'files' => $files,
			'digest' => hash_final($digest),
		];
	}//end read()

	/**
	 * Every file under a directory, by its path relative to the package.
	 *
	 * @param string $dir    The directory to read.
	 * @param string $prefix The relative path of that directory.
	 *
	 * @return array<string, string> The raw contents, by relative path.
	 */
	private function filesUnder(string $dir, string $prefix): array {
		$files = [];
		foreach ((array)scandir($dir) as $entry) {
			$entry = (string)$entry;
			if ($entry === '' || $entry[0] === '.') {
				continue;
			}

			$path = $dir . '/' . $entry;
			if (is_dir($path) === true) {
				$files = array_merge($files, $this->filesUnder(dir: $path, prefix: $prefix . $entry . '/'));
				continue;
			}

			$content = file_get_contents($path);
			if ($content !== false) {
				$files[$prefix . $entry] = $content;
			}
		}

		ksort($files);

		return $files;
	}//end filesUnder()

	/**
	 * The decoded .json files under one directory of a package, in name order.
	 *
	 * @param array<string, string> $files The package files, as read() gives them.
	 * @param string                $dir   The directory prefix.
	 *
	 * @return array<int, array<string, mixed>> The decoded files.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function rowsUnder(array $files, string $dir): array {
		$rows = [];
		foreach ($files as $path => $content) {
			if (str_starts_with($path, $dir) === true && str_ends_with($path, '.json') === true) {
				$rows[] = (array)json_decode($content, true);
			}
		}

		return $rows;
	}//end rowsUnder()
}//end class
