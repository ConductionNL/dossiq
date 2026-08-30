<?php

/**
 * Result value-object returned by a Dossiq ZTC / Catalogi-API
 * adapter call.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Ztc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Ztc;

/**
 * Result of a ZTC / Catalogi-API resolve / import attempt.
 *
 * `outcome` is one of `FOUND`, `IMPORTED`, `NOT_FOUND`,
 * `LOOKUP_DEFERRED`, `IMPORT_DEFERRED`, `ZTC_ERROR`. The dormant
 * default uses `LOOKUP_DEFERRED` for resolve and `IMPORT_DEFERRED`
 * for import so a caller can branch on the prefix.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class ZtcResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $outcome FOUND / IMPORTED / NOT_FOUND /
	 *                        LOOKUP_DEFERRED / IMPORT_DEFERRED /
	 *                        ZTC_ERROR.
	 * @param string $url Resolved or imported canonical
	 *                    URL (receiver-side for FOUND,
	 *                    tenant-local for IMPORTED;
	 *                    empty for non-FOUND/IMPORTED).
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras —
	 *                                    receiverSourceSlug,
	 *                                    zaaktypeOmschrijving,
	 *                                    catalogusUrl, errorBody.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $url,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
