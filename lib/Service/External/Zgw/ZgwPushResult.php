<?php

/**
 * Result value-object returned by a Procest external-ZGW push call.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Zgw;

/**
 * Result of an external-ZGW push attempt.
 *
 * `pushStatus` is one of `PUSHED`, `REJECTED`, `PUSH_DEFERRED`,
 * `PUSH_ERROR`. `PUSHED` means the receiver accepted the envelope
 * and returned a canonical URL; `REJECTED` means the receiver
 * rejected on schema or authorization grounds; `PUSH_DEFERRED` is
 * the dormant default.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class ZgwPushResult
{
    /**
     * Construct the result value-object.
     *
     * @param string              $pushStatus   PUSHED / REJECTED /
     *                                          PUSH_DEFERRED /
     *                                          PUSH_ERROR.
     * @param string              $receiverUrl  Receiver-side canonical
     *                                          URL of the created
     *                                          resource (empty for non-
     *                                          PUSHED).
     * @param string              $correlationId Echoed input
     *                                           correlation id; empty
     *                                           if caller did not
     *                                           supply one.
     * @param bool                $dormant      TRUE when the adapter was
     *                                          dormant.
     * @param array<string,mixed> $extras       Provider-specific extras —
     *                                          receiverSourceSlug,
     *                                          rejectionReason,
     *                                          autorisatieScope.
     */
    public function __construct(
        public readonly string $pushStatus,
        public readonly string $receiverUrl,
        public readonly string $correlationId,
        public readonly bool $dormant,
        public readonly array $extras = [],
    ) {
    }//end __construct()
}//end class
