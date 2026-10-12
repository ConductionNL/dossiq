<?php

/**
 * The named sets dossiq ships, and the version each is at.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

use OCA\Dossiq\Service\CaseDefinition\PackageDirectory;

/**
 * Every set dossiq seeds, named once, with the version it is at.
 *
 * 🔑 THE VERSION IS BUMPED BY HAND, AND THAT IS THE POINT. A version derived
 * from the app version moves on every release whether or not the seed file
 * changed, and an administrator would be offered an adoption that changes
 * nothing, once a fortnight, until they stopped reading the screen. Change a
 * seed file, bump the set here in the same commit.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class ShippedSets {

	/**
	 * The VTH case types, seeded from the shipped vergunning, toezicht and
	 * handhaving configuration.
	 *
	 * The directory it comes from is deliberately not written out here:
	 * `tests/Unit/Settings/WorkflowGuardConformanceTest.php` scans lib/ for PHP
	 * that names that path, because a loader for it would put unevaluable
	 * guards in front of the engine. This class loads nothing.
	 */
	public const VTH = 'vth';

	/**
	 * The named municipal role set, shipped dormant.
	 */
	public const MUNICIPAL_ROLES = 'gemeentelijke-rollen';

	/**
	 * The version each set is at.
	 *
	 * @var array<string, string>
	 */
	public const VERSIONS = [
		self::VTH => '1.0.0',
		self::MUNICIPAL_ROLES => '1.0.0',
	];

	/**
	 * Constructor.
	 *
	 * @param PackageDirectory|null $packages The shipped packages, whose
	 *                                        manifests carry their own
	 *                                        version; null for the
	 *                                        constants only.
	 */
	public function __construct(
		private readonly ?PackageDirectory $packages = null,
	) {
	}//end __construct()

	/**
	 * The version of one set.
	 *
	 * Fails closed on an unknown name: '' is what
	 * {@see ShippedConfigurationService} reads as "this set has no declared
	 * version", and it refuses to stamp rather than writing a provenance row
	 * that says nothing. ADR-102.
	 *
	 * An instance method over a table of constants, and injected rather than
	 * reached statically, so a caller can be tested against a set list that is
	 * not the shipped one.
	 *
	 * @param string $set The set name.
	 *
	 * @return string The version, or '' when the set is not declared here.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function versionOf(string $set): string {
		if (isset(self::VERSIONS[$set]) === true) {
			return self::VERSIONS[$set];
		}

		return $this->packageVersion(set: $set);
	}//end versionOf()

	/**
	 * The version a shipped package declares, when the set is a package.
	 *
	 * A package names itself and its version in its own `package.json`, so
	 * it needs no line in {@see self::VERSIONS}: the version is still bumped
	 * by hand, in the manifest, in the commit that changes the package.
	 *
	 * @param string $set The set name, which for a package is its id.
	 *
	 * @return string The version, or '' when no shipped package has that id.
	 */
	private function packageVersion(string $set): string {
		if ($this->packages === null) {
			return '';
		}

		$package = $this->packages->read(id: $set);
		if ($package === null) {
			return '';
		}

		$manifest = json_decode((string)($package['files'][PackageDirectory::MANIFEST] ?? ''), true);
		if (is_array($manifest) === false || preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) !== 1) {
			return '';
		}

		return (string)$manifest['version'];
	}//end packageVersion()
}//end class
