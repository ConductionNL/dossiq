<?php

/**
 * Rewrites one wrong stored value back in the dossiq register.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Finds the rows holding a wrong value and writes the right one.
 *
 * Filters on the WRONG value rather than reading every row, so a step using it
 * is idempotent: a repaired row no longer matches.
 *
 * @spec exclude Corrective data migration helper shared by the vocabulary repair steps.
 */
final class StoredValueRewriter {

	use SearchesObjects;

	/**
	 * The dossiq register slug.
	 */
	private const REGISTER_SLUG = 'dossiq';

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly object $objectService,
	) {
	}//end __construct()

	/**
	 * Rewrite every row holding the wrong value.
	 *
	 * @param ValueRewrite $rewrite The value to move back.
	 *
	 * @return integer The number of rows rewritten.
	 *
	 * @spec exclude Corrective data migration helper shared by the vocabulary repair steps.
	 */
	public function rewrite(ValueRewrite $rewrite): int {
		$rows = $this->searchObjectsAsArrays(
			objectService: $this->objectService,
			register: self::REGISTER_SLUG,
			schema: $rewrite->schema,
			filters: [$rewrite->property => $rewrite->wrong, '_limit' => 5000],
		);

		$repaired = 0;
		foreach ($rows as $row) {
			if ($this->rewriteRow(rewrite: $rewrite, row: $row) === true) {
				$repaired++;
			}
		}

		return $repaired;
	}//end rewrite()

	/**
	 * Write the right value on one row, when it really holds the wrong one.
	 *
	 * @param ValueRewrite         $rewrite The value to move back.
	 * @param array<string, mixed> $row     The row as the search returned it.
	 *
	 * @return bool True when the row was rewritten and saved.
	 */
	private function rewriteRow(ValueRewrite $rewrite, array $row): bool {
		// Defensive: a filter the backend cannot express server-side would
		// return everything, and relabelling a row that holds another value
		// would be a far worse bug than the one being fixed.
		if (($row[$rewrite->property] ?? null) !== $rewrite->wrong) {
			return false;
		}

		$row[$rewrite->property] = $rewrite->right;

		$saved = $this->saveObjectAsArray(
			objectService: $this->objectService,
			register: self::REGISTER_SLUG,
			schema: $rewrite->schema,
			object: $row,
		);

		return ($saved !== null);
	}//end rewriteRow()
}//end class
