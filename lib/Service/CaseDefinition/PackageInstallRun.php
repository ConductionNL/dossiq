<?php

/**
 * Dossiq case definition package install run.
 *
 * What one install of a valid package works from: the package id and
 * version, its declared parts, its files and digest, and whether a row
 * changed here takes the package's keys.
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
 * One install of a valid package.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
final class PackageInstallRun {

	/**
	 * The package version.
	 *
	 * @var string
	 */
	public readonly string $version;

	/**
	 * The declared parts.
	 *
	 * @var array<int, mixed>
	 */
	public readonly array $parts;

	/**
	 * The package files.
	 *
	 * @var array<string, string>
	 */
	public readonly array $files;

	/**
	 * The digest of the package files.
	 *
	 * @var string
	 */
	public readonly string $digest;

	/**
	 * The capabilities the manifest names.
	 *
	 * @var array<int, string>
	 */
	private readonly array $requires;

	/**
	 * Constructor.
	 *
	 * @param string               $id               The package id.
	 * @param array<string, mixed> $manifest         The manifest.
	 * @param array<string, mixed> $package          The package as PackageDirectory::read() gives it.
	 * @param boolean              $overLocalChanges Whether a row changed here takes the package's keys.
	 */
	public function __construct(
		public readonly string $id,
		array $manifest,
		array $package,
		public readonly bool $overLocalChanges,
	) {
		$this->version = (string)($manifest['version'] ?? '');
		$this->requires = array_map('strval', (array)($manifest['requires'] ?? []));
		$this->parts = (array)($manifest['parts'] ?? []);
		$this->files = (array)$package['files'];
		$this->digest = (string)$package['digest'];
	}//end __construct()

	/**
	 * Everything the package requires: what it names, and what its parts imply.
	 *
	 * @return array<int, string> The requirements.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function requirements(): array {
		$requires = $this->requires;
		foreach ($this->parts as $part) {
			$requires[] = (ShippedPackageRules::PART_CAPABILITY[(string)$part] ?? (string)$part);
		}

		return array_values(array_unique($requires));
	}//end requirements()

	/**
	 * The package's settings: settings.json decoded, or none without a settings part.
	 *
	 * @return array<mixed> The settings.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function settings(): array {
		if (in_array('settings', $this->parts, true) === false) {
			return [];
		}

		return (array)json_decode($this->files['settings.json'], true);
	}//end settings()
}//end class
