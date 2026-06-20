<?php

/**
 * Procest e-Depot submission port.
 *
 * The `archief-edepot-handover-05-sip-submission` member packages
 * the TMLO/MDTO metadata + exported documents into a BagIt SIP and
 * submits it to the tenant's certified e-Depot (currently any of
 * the Nationaal Archief / regionale historische centra / private
 * suppliers) over HTTPS / SFTP / S3. Each submission attempt is
 * recorded as an `OverdrachtTransactie` with the archief-id
 * returned by the e-Depot.
 *
 * The port is intentionally narrow — one `submit()` method
 * returning a structured result — so the production binding
 * (openconnector source slug `archief-edepot`, transport-specific
 * credentials, retry/back-off policy) can be swapped in via
 * `Application::register()` without touching the SIP-submission
 * orchestrator.
 *
 * Until that binding is configured, the default binding is dormant:
 * it logs the submit intent and returns a synthetic
 * `SUBMISSION_DEFERRED` outcome so the surrounding lifecycle
 * (member 06 proof / rollback, member 07 batch inspection) stays
 * observable in test + staging environments without attempting
 * to upload a (likely-stub) SIP to a live e-Depot.
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Tmlo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://www.nationaalarchief.nl/digitale-overheid/edepot
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Tmlo;

/**
 * E-Depot submission port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the submit intent and returns a
 * synthetic SUBMISSION_DEFERRED outcome so the surrounding lifecycle
 * can advance into `pending-edepot-submission` without contacting
 * an e-Depot.
 *
 * Activation steps for a real e-Depot binding:
 *  1. Provision the tenant's e-Depot account + transport
 *     credentials (HTTPS API token / SFTP key pair / S3 IAM key)
 *     in openconnector under source slug `archief-edepot`.
 *  2. Configure the per-tenant `archief-id` mapping rule so
 *     OverdrachtTransactie records carry the correct archief
 *     identifier.
 *  3. Override the EDepotSubmissionAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 */
interface EDepotSubmissionAdapterInterface
{
    /**
     * Submit a BagIt SIP bundle to the configured e-Depot.
     *
     * @param string              $sipBundelId Pointer at the persisted
     *                                         SipBundel record (carries
     *                                         the staged BagIt directory
     *                                         + manifest).
     * @param array<string,mixed> $context     Optional context —
     *                                         transportMode (`https` |
     *                                         `sftp` | `s3`),
     *                                         retryCount,
     *                                         correlationId.
     *
     * @return EDepotSubmissionResult The dispatch outcome — `archiefId`
     *                                + `overdrachtTransactieId` echoed
     *                                back to the orchestrator for
     *                                proof / rollback.
     */
    public function submit(string $sipBundelId, array $context=[]): EDepotSubmissionResult;

    /**
     * Whether the adapter is dormant — i.e. wired but not contacting
     * an e-Depot.
     *
     * @return bool TRUE when the adapter is a log-only stub.
     */
    public function isDormant(): bool;
}//end interface
