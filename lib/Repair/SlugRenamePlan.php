<?php

/**
 * The slug-rename decisions both slug repair steps take.
 *
 * MigrateRegisterSlug renames register slugs and RenameDutchSchemaSlugs renames
 * schema slugs, and both decide the same way which renames may go ahead. This
 * holds that decision once. Like the classes that call it, it takes plain
 * arrays and scalars and touches neither database nor logger.
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * Plans slug renames against the slugs an install holds.
 *
 * @spec exclude No canonical spec covers the slug repair steps. Pointing this
 *  at an existing spec would report conformance to a requirement that says
 *  nothing about it.
 */
final class SlugRenamePlan {

	/**
	 * Decide which slugs may be renamed, given what the install holds.
	 *
	 * An old slug that is absent is nothing to do, not a refusal. A target that
	 * is already present refuses the rename: two rows cannot share a slug, and
	 * merging them is a decision about data. `$existing` grows as renames are
	 * accepted, so a later entry targeting the same name is refused too.
	 *
	 * @param array<string, string> $map      Old slug => new slug.
	 * @param array<int, string>    $existing Slugs currently present.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
	 *
	 * @spec exclude No canonical spec covers the slug repair steps.
	 */
	public function plan(array $map, array $existing): array {
		$renames = [];
		$refused = [];

		foreach ($map as $old => $new) {
			if (in_array($old, $existing, true) === false) {
				// Not on this install — not a refusal, just nothing to do.
				continue;
			}

			if (in_array($new, $existing, true) === true) {
				$refused[$old] = sprintf("target slug '%s' already exists", $new);
				continue;
			}

			$renames[$old] = $new;
			$existing[] = $new;
		}

		return [
			'renames' => $renames,
			'refused' => $refused,
		];
	}//end plan()

	/**
	 * The slug column of a set of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with a `slug` column.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude No canonical spec covers the slug repair steps.
	 */
	public function slugsFrom(array $rows): array {
		return array_map(static fn (array $row): string => (string)($row['slug'] ?? ''), $rows);
	}//end slugsFrom()

	/**
	 * A comma-separated list of `?` placeholders for an IN clause.
	 *
	 * @param int $count How many.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the slug repair steps.
	 */
	public function placeholders(int $count): string {
		return implode(',', array_fill(0, max(0, $count), '?'));
	}//end placeholders()
}//end class
