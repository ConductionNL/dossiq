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
	 * The bezwaar and beroep case types seeded from `bezwaar_seed_data.json`.
	 */
	public const BEZWAAR_BEROEP = 'bezwaar-beroep';

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
		self::BEZWAAR_BEROEP => '1.0.0',
		self::VTH => '1.0.0',
		self::MUNICIPAL_ROLES => '1.0.0',
	];

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
		return (self::VERSIONS[$set] ?? '');
	}//end versionOf()
}//end class
