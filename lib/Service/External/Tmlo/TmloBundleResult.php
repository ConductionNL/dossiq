<?php

/**
 * Result value-object returned by a Procest TMLO / MDTO builder call.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Tmlo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

/**
 * Result of a TMLO / MDTO metadata-bundle build attempt.
 *
 * `buildStatus` is one of `BUILT_VALID`, `BUILT_INVALID`,
 * `BUILD_DEFERRED`, `BUILD_ERROR`. `BUILT_VALID` means the live
 * binding produced XSD-valid XML; `BUILT_INVALID` means the inputs
 * did not satisfy the chosen MDTO/TMLO XSD (the chain stops here);
 * `BUILD_DEFERRED` is the dormant default.
 *
 * @spec openspec/changes/archief-edepot-handover-03-metadata-bundling/specs/archief-edepot-handover/spec.md
 */
final class TmloBundleResult
{
    /**
     * Construct the result value-object.
     *
     * @param string              $buildStatus        BUILT_VALID /
     *                                                BUILT_INVALID /
     *                                                BUILD_DEFERRED /
     *                                                BUILD_ERROR.
     * @param string              $caseId             Echoed input.
     * @param string              $metadataXml        The TMLO/MDTO XML
     *                                                payload (empty for
     *                                                BUILT_INVALID /
     *                                                BUILD_ERROR; a stub
     *                                                comment for
     *                                                BUILD_DEFERRED).
     * @param string              $metadataXsdVersion XSD pin echoed back
     *                                                (e.g. `mdto-1.1`).
     * @param bool                $dormant            TRUE when the
     *                                                adapter was
     *                                                dormant.
     * @param array<string,mixed> $extras             Provider-specific
     *                                                extras — xsdErrors[],
     *                                                builderVersion,
     *                                                archiefvormerId.
     */
    public function __construct(
        public readonly string $buildStatus,
        public readonly string $caseId,
        public readonly string $metadataXml,
        public readonly string $metadataXsdVersion,
        public readonly bool $dormant,
        public readonly array $extras=[],
    ) {
    }//end __construct()
}//end class
