<?php

/**
 * Procest Beschikking Archival Adapter Interface.
 *
 * Contract for recording a beschikking in durable archival storage. The
 * OpenRegisterArchivalAdapter implementation records the beschikking on
 * OpenRegister's declarative archival pipeline (x-openregister-archival on the
 * case schema) and returns the Archiefwet vernietigingsdatum derived from the
 * declared bewaartermijn (migrate-archival-to-or, ADR-022).
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T25
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

/**
 * Ingests a beschikking into durable archival storage (OpenRegister).
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T25
 */
interface ArchivalAdapterInterface
{
    /**
     * Ingest a beschikking with its metadata into the archief.
     *
     * @param string               $beschikkingId The beschikking UUID.
     * @param string               $bestandId     The Nextcloud file id of the signed PDF/A-3.
     * @param array<string, mixed> $tmloMetadata  The TMLO-1.2 or MDTO metadata block.
     *
     * @return array{archiefId: string, vernietigingsdatum: string} The archival result.
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T25
     */
    public function ingest(string $beschikkingId, string $bestandId, array $tmloMetadata): array;
}//end interface
