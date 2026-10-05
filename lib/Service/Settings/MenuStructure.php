<?php

/**
 * Which structure the app shows: the simple one or the full one.
 *
 * Dossiq builds two shapes of itself from one manifest. `simple` is the
 * default: nine menu entries under three captions, the work of a case
 * handler's day. `full` is the navigation and the pages as they were before
 * the profile existed. An administrator picks one on the admin settings page,
 * and the page controller hands the choice to the frontend as initial state.
 *
 * Nothing is removed in either. Both are built from the same manifest, so
 * every page keeps its route and every deep link keeps working.
 *
 * The frontend half is `src/utils/structureProfile.js`, and
 * `MenuStructureTest` fails when the two spell the key or the words
 * differently.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

/**
 * The structure setting: its key, its two values and how a stored value reads.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md
 */
class MenuStructure {

	/**
	 * The appconfig key, and the initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const KEY = 'menu_structure';

	/**
	 * The default structure.
	 *
	 * @var string
	 */
	public const SIMPLE = 'simple';

	/**
	 * The structure as it was before the profile existed.
	 *
	 * @var string
	 */
	public const FULL = 'full';

	/**
	 * The structure a stored value stands for.
	 *
	 * Only the word `full` selects the full structure. An unset key, an empty
	 * string and a typing mistake all read as `simple`, because the default
	 * has to be the answer whenever the setting does not clearly say otherwise.
	 *
	 * @param string $stored The stored appconfig value.
	 *
	 * @return string `simple` or `full`.
	 *
	 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
	 */
	public function normalise(string $stored): string {
		if (strtolower(trim($stored)) === self::FULL) {
			return self::FULL;
		}

		return self::SIMPLE;
	}//end normalise()
}//end class
