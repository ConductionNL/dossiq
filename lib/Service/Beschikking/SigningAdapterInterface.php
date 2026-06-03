<?php

/**
 * Procest Beschikking Signing Adapter Interface.
 *
 * Contract for the OpenConnector eIDAS-TSP signing integration. The real
 * adapter (delivered in the openconnector repo, change task T23) orchestrates
 * a qualified Trust Service Provider flow and returns the signed PDF plus a
 * durable validatierapport id; the MockAdapter returns a deterministic stub.
 *
 * @category Interface
 * @package  OCA\Procest\Service\Beschikking
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T23
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

/**
 * Signs a beschikking PDF via an eIDAS-qualified TSP (OpenConnector).
 */
interface SigningAdapterInterface
{
    /**
     * Sign a beschikking via the chosen TSP.
     *
     * @param string $bestandId     The Nextcloud file id of the rendered PDF.
     * @param string $ondertekenaar The signer's Nextcloud UID.
     * @param string $tspProvider   The TSP provider slug.
     *
     * @return array<string, string> Signature metadata keyed by signedBestandId, validatieRapportId, certificaatSerienummer, tspProviderEidasId.
     */
    public function sign(string $bestandId, string $ondertekenaar, string $tspProvider): array;

    /**
     * Fetch a previously produced validatierapport by id (for audit export).
     *
     * @param string $validatieRapportId The validatierapport id.
     *
     * @return array<string, mixed> The validatierapport contents.
     */
    public function fetchValidationReport(string $validatieRapportId): array;
}//end interface
