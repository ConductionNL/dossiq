<?php

/**
 * Procest Mock Archival Adapter.
 *
 * Deterministic stand-in for the OpenRegister archief-ingest endpoint. Used
 * until the real archival adapter (task T25) is available. Computes a
 * vernietigingsdatum 15 years after the creatieDatum in the supplied metadata
 * (the WMO default retention) so the ArchivalJob is testable in isolation.
 *
 * @category Service
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

use DateInterval;
use DateTimeImmutable;
use Exception;

/**
 * Mock implementation of the archival adapter.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T25
 */
class MockArchivalAdapter implements ArchivalAdapterInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string               $beschikkingId The beschikking UUID.
     * @param string               $bestandId     The signed PDF file id.
     * @param array<string, mixed> $tmloMetadata  The metadata block.
     *
     * @return array{archiefId: string, vernietigingsdatum: string}
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T25
     */
    public function ingest(string $beschikkingId, string $bestandId, array $tmloMetadata): array
    {
        $creatie = (string) ($tmloMetadata['creatieDatum'] ?? ($tmloMetadata['bekendmakingDatum'] ?? ''));

        try {
            $base = new DateTimeImmutable();
            if ($creatie !== '') {
                $base = new DateTimeImmutable($creatie);
            }
        } catch (Exception $e) {
            $base = new DateTimeImmutable();
        }

        $vernietiging = $base->add(new DateInterval('P15Y'));

        return [
            'archiefId'          => 'openregister-'.substr(hash('sha256', $beschikkingId.$bestandId), 0, 12),
            'vernietigingsdatum' => $vernietiging->format('Y-m-d'),
        ];
    }//end ingest()
}//end class
