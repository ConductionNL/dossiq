<?php

/**
 * Procest Doorverbinding Service.
 *
 * Warm-transfer orchestration: captures an immutable context-snapshot at
 * transfer time, records the doorverbinding, and lets the receiving specialist
 * accept or reject. Handover notes are append-only; the original context
 * snapshot is never mutated, preserving the full context trail.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Orchestrates warm doorverbindingen with immutable context-overdracht.
 */
class DoorverbindingService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build an immutable context snapshot for a transfer.
     *
     * @param array<string, mixed> $contact   The originating contactmoment data.
     * @param array<int, mixed>    $zaken     The related case summaries.
     * @param array<string, mixed> $sentiment The sentiment data, if any.
     *
     * @return string A JSON-encoded immutable snapshot.
     */
    public function createContextSnapshot(array $contact, array $zaken, array $sentiment): string
    {
        $snapshot = [
            'capturedAt'               => date('c'),
            'bellerIdentificatie'      => (string) ($contact['bellerIdentificatie'] ?? ''),
            'geidentificeerdeBurgerId' => ($contact['geidentificeerdeBurgerId'] ?? null),
            'samenvatting'             => (string) ($contact['samenvatting'] ?? ''),
            'gerelateerdeZaken'        => array_values($zaken),
            'sentiment'                => $sentiment,
        ];

        return (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    }//end createContextSnapshot()

    /**
     * Initiate a warm transfer and persist the doorverbinding record.
     *
     * @param array<string, mixed> $data The transfer fields.
     *
     * @return array<string, mixed> The created doorverbinding record.
     *
     * @throws RuntimeException When the schema is unconfigured or the write fails.
     */
    public function initiateWarmTransfer(array $data): array
    {
        $contactmomentId = trim((string) ($data['contactmomentId'] ?? ''));
        $vanMedewerkerId = trim((string) ($data['vanMedewerkerId'] ?? ''));
        if ($contactmomentId === '' || $vanMedewerkerId === '') {
            throw new RuntimeException('contactmomentId and vanMedewerkerId are required');
        }

        [$objectService, $register, $schema] = $this->resolve();

        $record = [
            'contactmomentId'      => $contactmomentId,
            'vanMedewerkerId'      => $vanMedewerkerId,
            'naarMedewerkerId'     => ($data['naarMedewerkerId'] ?? null),
            'naarWachtrij'         => ($data['naarWachtrij'] ?? null),
            'doorverbindingsReden' => (string) ($data['doorverbindingsReden'] ?? ''),
            'contextOverdracht'    => (string) ($data['contextOverdracht'] ?? ''),
            'contextSnapshot'      => (string) ($data['contextSnapshot'] ?? '{}'),
            'geaccepteerd'         => null,
            'warmTransferStarted'  => date('c'),
        ];

        try {
            $created = $objectService->saveObject($register, $schema, $record);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to initiate doorverbinding: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Could not initiate doorverbinding');
        }

        return $this->toArray(result: $created);
    }//end initiateWarmTransfer()

    /**
     * Mark a doorverbinding as accepted by the receiving specialist.
     *
     * @param string $doorverbindingId The doorverbinding UUID.
     *
     * @return array<string, mixed> The updated record.
     *
     * @throws RuntimeException When already answered or the update fails.
     */
    public function acceptTransfer(string $doorverbindingId): array
    {
        $current = $this->load(doorverbindingId: $doorverbindingId);
        if (($current['geaccepteerd'] ?? null) !== null) {
            throw new RuntimeException('Doorverbinding already answered');
        }

        return $this->update(
            doorverbindingId: $doorverbindingId,
            patch: [
                'geaccepteerd'   => true,
                'acceptatieTijd' => date('c'),
            ],
        );
    }//end acceptTransfer()

    /**
     * Mark a doorverbinding as rejected with a reason.
     *
     * @param string $doorverbindingId The doorverbinding UUID.
     * @param string $reden            The rejection reason.
     *
     * @return array<string, mixed> The updated record.
     *
     * @throws RuntimeException When already answered, reason missing, or update fails.
     */
    public function rejectTransfer(string $doorverbindingId, string $reden): array
    {
        $reden = trim($reden);
        if ($reden === '') {
            throw new RuntimeException('Rejection reason is required');
        }

        $current = $this->load(doorverbindingId: $doorverbindingId);
        if (($current['geaccepteerd'] ?? null) !== null) {
            throw new RuntimeException('Doorverbinding already answered');
        }

        return $this->update(
            doorverbindingId: $doorverbindingId,
            patch: [
                'geaccepteerd'   => false,
                'afgekeurdReden' => $reden,
            ],
        );
    }//end rejectTransfer()

    /**
     * Append handover notes to a doorverbinding without overwriting prior notes.
     *
     * @param string $doorverbindingId The doorverbinding UUID.
     * @param string $notes            The notes to append.
     * @param string $specialistUid    The appending specialist UID.
     *
     * @return array<string, mixed> The updated record.
     *
     * @throws RuntimeException When the update fails.
     */
    public function appendContextNotes(string $doorverbindingId, string $notes, string $specialistUid): array
    {
        $current  = $this->load(doorverbindingId: $doorverbindingId);
        $existing = (string) ($current['contextOverdracht'] ?? '');

        $entry  = '['.date('c').' '.$specialistUid.'] '.trim($notes);
        $merged = $entry;
        if ($existing !== '') {
            $merged = $existing."\n".$entry;
        }

        return $this->update(doorverbindingId: $doorverbindingId, patch: ['contextOverdracht' => $merged]);
    }//end appendContextNotes()

    /**
     * Load a doorverbinding record by id.
     *
     * @param string $doorverbindingId The doorverbinding UUID.
     *
     * @return array<string, mixed> The record.
     *
     * @throws RuntimeException When not found or unconfigured.
     */
    private function load(string $doorverbindingId): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        try {
            $record = $objectService->find($doorverbindingId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            throw new RuntimeException('Doorverbinding not found');
        }

        return $this->toArray(result: $record);
    }//end load()

    /**
     * Persist a partial update to a doorverbinding.
     *
     * @param string               $doorverbindingId The doorverbinding UUID.
     * @param array<string, mixed> $patch            The fields to update.
     *
     * @return array<string, mixed> The updated record.
     *
     * @throws RuntimeException When the update fails.
     */
    private function update(string $doorverbindingId, array $patch): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        try {
            $updated = $objectService->saveObject($register, $schema, $patch, $doorverbindingId);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to update doorverbinding: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Could not update doorverbinding');
        }

        return $this->toArray(result: $updated);
    }//end update()

    /**
     * Resolve the ObjectService, register and schema for doorverbinding.
     *
     * @return array{0: object, 1: string, 2: string}
     *
     * @throws RuntimeException When OpenRegister or schema is unavailable.
     */
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('doorverbinding_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Doorverbinding schema is not configured');
        }

        return [$objectService, $register, $schema];
    }//end resolve()

    /**
     * Normalise an ObjectService result into a plain array.
     *
     * @param mixed $result The ObjectService result.
     *
     * @return array<string, mixed> The normalised record.
     */
    private function toArray($result): array
    {
        if (is_array($result) === true) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            return (array) $result->jsonSerialize();
        }

        if (is_object($result) === true) {
            return (array) $result;
        }

        return [];
    }//end toArray()
}//end class
