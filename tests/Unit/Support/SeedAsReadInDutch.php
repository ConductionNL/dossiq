<?php

/**
 * Seed rows as a Dutch reader gets them.
 *
 * The register seed writes translatable labels as language maps
 * (`{"nl": "Ontvangen", "en": "Received"}`); OpenRegister answers a reader
 * one language of each. Tests that read the seed file as data read it the way
 * a Dutch reader gets it, so a map compares as its Dutch text.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

/**
 * Resolves the seed's language maps to their Dutch text.
 */
final class SeedAsReadInDutch {
	/**
	 * The value with every `{nl, en}` map replaced by its Dutch text, at any depth.
	 *
	 * @param mixed $value A decoded seed value.
	 *
	 * @return mixed The value as a Dutch reader gets it.
	 */
	public static function resolve(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (isset($value['nl']) === true && is_string($value['nl']) === true && array_diff(array_keys($value), ['nl', 'en']) === []) {
			return $value['nl'];
		}

		return array_map(static fn (mixed $entry): mixed => self::resolve(value: $entry), $value);
	}//end resolve()
}//end class
