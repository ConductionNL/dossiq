<?php

/**
 * The pure decisions behind RenameDutchSchemaSlugs.
 *
 * A collaborator rather than static helpers, because the ruleset forbids static
 * access — and an object keeps these testable on their own. They take plain
 * arrays and scalars and touch neither database nor logger, which is what makes
 * the DECISION unit-testable while the DDL that follows it is not.
 *
 * Same split as softwarecatalog's RenameDutchSchemaSlugDecisions, for the same reason: a repair step
 * that reaches the database cannot be exercised without one, so everything that
 * can be decided before touching it is decided here.
 *
 * @category  Repair
 * @package   OCA\Procest\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

/**
 * Pure predicates for the Dutch-to-English schema slug migration.
 */
class RenameDutchSchemaSlugDecisions {

	/**
	 * Decide which slugs may be renamed, given what the install actually holds.
	 *
	 * Returns the renames in the order they must be applied, plus the ones
	 * refused and why. Two schemas cannot share a slug, so a target that is
	 * already present means BOTH are left alone: merging them is a decision
	 * about data, not a rename.
	 *
	 * The `$existing` set is updated as it goes, so a rename earlier in the map
	 * is visible to the collision check of a later one — otherwise two entries
	 * targeting the same name would both look safe.
	 *
	 * @param array<string, string> $map      Old slug => new slug.
	 * @param array<int, string>    $existing Slugs currently present.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
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
	 * Pull the schema ids out of the registers' `schemas` JSON column.
	 *
	 * The column is JSON, and a register row can carry null, a malformed value
	 * or a list with non-numeric entries. Every one of those must yield "no ids"
	 * rather than a fatal, because this runs inside a repair step where an
	 * exception aborts the upgrade.
	 *
	 * @param array<int, array<string, mixed>> $rows Register rows.
	 *
	 * @return array<int, int> Distinct schema ids.
	 */
	public function schemaIdsFrom(array $rows): array {
		$ids = [];

		foreach ($rows as $row) {
			$decoded = json_decode((string)($row['schemas'] ?? '[]'), true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach ($decoded as $id) {
				if (is_numeric($id) === true) {
					$ids[] = (int)$id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end schemaIdsFrom()

	/**
	 * Build the `?,?,?` placeholder list for an IN clause.
	 *
	 * Trivial, and here rather than inline because the step builds one three
	 * times and a mismatch between the placeholder count and the bound
	 * parameters is the kind of error that only shows up at runtime, inside a
	 * repair step, on somebody else's install.
	 *
	 * @param int $count Number of bound parameters.
	 *
	 * @return string The placeholder list.
	 */
	public function placeholders(int $count): string {
		return implode(',', array_fill(0, max(0, $count), '?'));
	}//end placeholders()
	/**
	 * Pull the slugs out of schema rows.
	 *
	 * Sibling of schemaIdsFrom(), and defensive for the same reason: a row with
	 * a null slug must yield an empty string rather than a TypeError inside a
	 * repair step, where an exception aborts the upgrade.
	 *
	 * @param array<int, array<string, mixed>> $rows Schema rows.
	 *
	 * @return array<int, string> The slugs.
	 */
	public function slugsFrom(array $rows): array {
		return array_map(static fn (array $row): string => (string)($row['slug'] ?? ''), $rows);
	}//end slugsFrom()
}//end class
