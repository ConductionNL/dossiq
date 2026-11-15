<?php

/**
 * Whether an OpenRegister object payload belongs to a configured schema.
 *
 * An object names its schema under `@self.schema` or at the top level, as an
 * id, a slug or a URL that ends in one. Many listeners ask the same question
 * of a payload against the schema a setting names; this answers it once.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

/**
 * Matches an object payload against a configured schema id or slug.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
final class SchemaMembership {

	/**
	 * Whether the payload's schema is the expected one, or a URL ending in it.
	 *
	 * An empty expectation matches nothing, and neither does a payload that
	 * names no schema.
	 *
	 * @param array<string, mixed> $object   Object payload (incl. `@self`).
	 * @param string               $expected The configured schema id or slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public static function belongsTo(array $object, string $expected): bool {
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end belongsTo()
}//end class
