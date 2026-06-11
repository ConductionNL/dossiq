<?php

/**
 * Result value-object returned by a Procest BRP / Haal Centraal
 * adapter call.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brp;

/**
 * Result of a BRP / Haal Centraal lookup attempt.
 *
 * `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `LOOKUP_DEFERRED`,
 * `LOOKUP_ERROR`. The `persoon` envelope deliberately omits the BSN
 * (already known to the caller) so AVG-classified data does not
 * persist beyond what the autorisatieprofiel-protected lifecycle
 * needs.
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 */
final class BrpLookupResult
{
    /**
     * Construct the result value-object.
     *
     * @param string              $lookupStatus FOUND / NOT_FOUND /
     *                                          LOOKUP_DEFERRED /
     *                                          LOOKUP_ERROR.
     * @param array<string,mixed> $persoon      Person envelope —
     *                                          naam{voornamen,geslachtsnaam,
     *                                          voorvoegsel}, geboorte{datum,
     *                                          land, plaats},
     *                                          verblijfplaats{adres,
     *                                          postcode, woonplaats,
     *                                          land}, geslachtsaanduiding,
     *                                          inOnderzoek — empty for
     *                                          NOT_FOUND / DEFERRED.
     * @param bool                $dormant      TRUE when the adapter was
     *                                          dormant.
     * @param array<string,mixed> $extras       Provider-specific extras —
     *                                          autorisatieprofielId,
     *                                          rateLimitRemaining.
     */
    public function __construct(
        public readonly string $lookupStatus,
        public readonly array $persoon,
        public readonly bool $dormant,
        public readonly array $extras = [],
    ) {
    }//end __construct()
}//end class
