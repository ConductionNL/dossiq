<?php

/**
 * Result value-object returned by a Dossiq BAG adapter call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Bag;

/**
 * Result of a BAG lookup attempt.
 *
 * `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `INVALID_INPUT`,
 * `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The `address` envelope is the
 * `BagResponseMapper`-normalized DTO (`street`, `houseNumber`,
 * `houseLetter`, `houseNumberAddition`, `postcode`, `city`, `gebruiksdoel`,
 * `oorspronkelijkBouwjaar`, `oppervlakte`, `geo`) — empty for anything
 * other than `FOUND`.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
final class BagLookupResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $lookupStatus FOUND / NOT_FOUND /
	 *                             INVALID_INPUT /
	 *                             LOOKUP_DEFERRED /
	 *                             LOOKUP_ERROR.
	 * @param array<string,mixed> $address Normalized address/object
	 *                                     envelope — empty unless
	 *                                     FOUND.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras —
	 *                                    tier, count/matches (for
	 *                                    multi-result address
	 *                                    searches), reason (on
	 *                                    error).
	 */
	public function __construct(
		public readonly string $lookupStatus,
		public readonly array $address,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
