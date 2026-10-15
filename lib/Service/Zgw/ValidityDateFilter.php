<?php

/**
 * Dossiq ZGW validity date filter
 *
 * The Catalogi API lists only the types valid on a date when a client asks
 * with `datumGeldigheid`. Split out of ZtcController (method-decomposition 8i).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Keeps the results that are valid on a date.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ValidityDateFilter {
	/**
	 * Filter a list of ZTC results by datumGeldigheid (date validity).
	 *
	 * Returns only items where beginGeldigheid <= datumGeldigheid and
	 * (eindeGeldigheid >= datumGeldigheid or eindeGeldigheid is absent).
	 *
	 * @param array $results The array of outbound-mapped result items.
	 * @param string $date The validity date in Y-m-d format.
	 *
	 * @return array The filtered results (re-indexed).
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function keep(array $results, string $date): array {
		$filtered = [];
		foreach ($results as $item) {
			$start = $item['startValidity'] ?? null;
			$end = $item['endValidity'] ?? null;

			// BeginGeldigheid must be present and <= datumGeldigheid.
			if ($start !== null && $start !== '' && $start > $date) {
				continue;
			}

			// EindeGeldigheid, if present, must be >= datumGeldigheid.
			if ($end !== null && $end !== '' && $end < $date) {
				continue;
			}

			$filtered[] = $item;
		}

		return $filtered;
	}//end keep()
}//end class
