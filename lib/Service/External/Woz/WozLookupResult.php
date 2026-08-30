<?php

/**
 * Result value-object returned by a Dossiq WOZ adapter call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Woz;

/**
 * Result of a WOZ lookup attempt.
 *
 * `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `INVALID_INPUT`,
 * `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The `wozObject` envelope is the
 * `WozResponseMapper`-normalized DTO (`wozobjectnummer`, `waarde`,
 * `waardepeildatum`, `grondoppervlakte`, `gebruiksdoel`,
 * `nummeraanduidingId`) — empty for anything other than `FOUND`.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
final class WozLookupResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $lookupStatus FOUND / NOT_FOUND /
	 *                             INVALID_INPUT /
	 *                             LOOKUP_DEFERRED /
	 *                             LOOKUP_ERROR.
	 * @param array<string,mixed> $wozObject Normalized WOZ object
	 *                                       envelope — empty unless
	 *                                       FOUND.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras —
	 *                                    tier, count/matches (for
	 *                                    multi-result searches),
	 *                                    reason (on error).
	 */
	public function __construct(
		public readonly string $lookupStatus,
		public readonly array $wozObject,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
