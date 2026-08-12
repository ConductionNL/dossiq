<?php

/**
 * Result value-object returned by a Procest BRK adapter call.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brk;

/**
 * Result of a BRK lookup attempt.
 *
 * `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `INVALID_INPUT`,
 * `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The `parcel` envelope is the
 * `BrkResponseMapper`-normalized DTO (`kadastraleGemeente`,
 * `kadastraleGemeenteCode`, `sectie`, `perceelnummer`,
 * `appartementsrechtVolgnummer`, `kadastraleAanduiding`, `oppervlakte`,
 * `soortCultuurBebouwd`, `zakelijkGerechtigden`, `geo`) — empty for
 * anything other than `FOUND`.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
final class BrkLookupResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $lookupStatus FOUND / NOT_FOUND /
	 *                             INVALID_INPUT /
	 *                             LOOKUP_DEFERRED /
	 *                             LOOKUP_ERROR.
	 * @param array<string,mixed> $parcel Normalized parcel envelope —
	 *                                    empty unless FOUND.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras —
	 *                                    tier, count/matches (for
	 *                                    multi-result searches),
	 *                                    reason (on error).
	 */
	public function __construct(
		public readonly string $lookupStatus,
		public readonly array $parcel,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
