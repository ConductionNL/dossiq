<?php

/**
 * Result value-object returned by a Procest e-Depot submission call.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

/**
 * Result of an e-Depot SIP submission attempt.
 *
 * `submissionStatus` is one of `ACCEPTED`, `REJECTED`,
 * `SUBMISSION_DEFERRED`, `RETRY_SCHEDULED`, `SUBMISSION_ERROR`.
 * `ACCEPTED` means the e-Depot returned an archief-id; `REJECTED`
 * means a hard rejection (proof / rollback path runs); `SUBMISSION_DEFERRED`
 * is the dormant default; `RETRY_SCHEDULED` triggers the exponential
 * back-off retry loop in OverdrachtTransactie.
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 */
final class EDepotSubmissionResult
{
    /**
     * Construct the result value-object.
     *
     * @param string              $submissionStatus       ACCEPTED /
     *                                                    REJECTED /
     *                                                    SUBMISSION_DEFERRED
     *                                                    /
     *                                                    RETRY_SCHEDULED
     *                                                    /
     *                                                    SUBMISSION_ERROR.
     * @param string              $sipBundelId            Echoed input.
     * @param string              $archiefId              e-Depot-side
     *                                                    archief
     *                                                    identifier
     *                                                    (empty for
     *                                                    non-
     *                                                    ACCEPTED).
     * @param string              $overdrachtTransactieId Synthetic
     *                                                    audit-trail id —
     *                                                    always present so
     *                                                    the OverdrachtTransactie
     *                                                    record stays
     *                                                    correlatable even
     *                                                    on dormant runs.
     * @param bool                $dormant                TRUE when the
     *                                                    adapter was
     *                                                    dormant.
     * @param array<string,mixed> $extras                 Provider-specific
     *                                                    extras —
     *                                                    transportMode,
     *                                                    rejectionReason,
     *                                                    nextRetryAt.
     */
    public function __construct(
        public readonly string $submissionStatus,
        public readonly string $sipBundelId,
        public readonly string $archiefId,
        public readonly string $overdrachtTransactieId,
        public readonly bool $dormant,
        public readonly array $extras=[],
    ) {
    }//end __construct()
}//end class
