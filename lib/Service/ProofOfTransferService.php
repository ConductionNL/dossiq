<?php

/**
 * Procest ProofOfTransferService.
 *
 * Records the ArchiefBewijs for a successful e-Depot handover and
 * verifies the persisted checksums against the SipBundel that was
 * shipped. Mismatches are flagged with status=alert-mismatch and
 * surface in the audit log.
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
 * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proof of e-Depot transfer.
 */
class ProofOfTransferService
{
    /**
     * Constructor.
     *
     * @param SettingsService        $settingsService Settings.
     * @param ArchivalTriggerService $triggerService  Trigger service (for audit log).
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ArchivalTriggerService $triggerService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record an ArchiefBewijs for a successful submission.
     *
     * @param string                 $caseId        Case id.
     * @param string                 $archivId      e-Depot archive id.
     * @param string                 $eDepotName    Name of the e-Depot.
     * @param string                 $sipBundelId   SipBundel id (for checksum verification).
     * @param string                 $receipt       Receipt payload from the e-Depot.
     * @param array<string, string>  $checksums     {algorithm: hex} as confirmed by the e-Depot.
     * @param DateTimeImmutable|null $ingestionDate Optional ingestion date.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function createArchiefBewijs(
        string $caseId,
        string $archivId,
        string $eDepotName,
        string $sipBundelId,
        string $receipt,
        array $checksums,
        ?DateTimeImmutable $ingestionDate=null
    ): array {
        $ingestionDate = ($ingestionDate ?? new DateTimeImmutable());

        $row = [
            'zaakId'               => $caseId,
            'archivId'             => $archivId,
            'eDepotNaam'           => $eDepotName,
            'ingestionDatum'       => $ingestionDate->format('Y-m-d\TH:i:sP'),
            'ontvangstBevestiging' => $receipt,
            'checksums'            => $checksums,
            'sipBundelId'          => $sipBundelId,
            'status'               => 'received',
            'createdAt'            => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];

        $saved = $this->save(schemaConfigKey: 'archief_bewijs_schema', object: $row);

        // Verify integrity in-place; flips status to verified / alert-mismatch.
        $bewijsId = (string) ($saved['id'] ?? '');
        if ($bewijsId !== '') {
            $verified = $this->verifyIntegrity(bewijsId: $bewijsId, sipBundelId: $sipBundelId);
            $saved    = $verified;
        }

        $this->triggerService->logEvent(null, $caseId, 'proof-captured', 'archivId='.$archivId);
        return $saved;
    }//end createArchiefBewijs()

    /**
     * Verify the e-Depot's confirmed checksums against the SipBundel.
     *
     * @param string $bewijsId    ArchiefBewijs id.
     * @param string $sipBundelId SipBundel id.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When bewijs/SIP not found.
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function verifyIntegrity(string $bewijsId, string $sipBundelId): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $bSchema       = (string) $this->settingsService->getConfigValue('archief_bewijs_schema');
        $sSchema       = (string) $this->settingsService->getConfigValue('sip_bundel_schema');
        if ($objectService === null || $register === '' || $bSchema === '' || $sSchema === '') {
            throw new RuntimeException('Archief services not configured');
        }

        $bewijs = $objectService->find($bewijsId, register: $register, schema: $bSchema);
        if (is_array($bewijs) === false) {
            throw new RuntimeException('ArchiefBewijs not found: '.$bewijsId);
        }

        $sip = $objectService->find($sipBundelId, register: $register, schema: $sSchema);
        if (is_array($sip) === false) {
            throw new RuntimeException('SipBundel not found: '.$sipBundelId);
        }

        $sipChecksum  = (string) ($sip['manifestChecksum'] ?? '');
        $confirmed    = (array) ($bewijs['checksums'] ?? []);
        $confirmedSha = (string) ($confirmed['sha256'] ?? '');

        $match = ($sipChecksum !== '' && $sipChecksum === $confirmedSha);
        if ($match === true) {
            $bewijs['status'] = 'verified';
        } else {
            $bewijs['status'] = 'alert-mismatch';
        }

        try {
            $saved = $objectService->saveObject($register, $bSchema, $bewijs);
            if (is_array($saved) === true) {
                $bewijs = $saved;
            }
        } catch (\Throwable $e) {
            $this->logger->error('ArchiefBewijs persist failed', ['id' => $bewijsId, 'error' => $e->getMessage()]);
        }

        if ($match === true) {
            $eventType   = 'proof-verified';
            $eventDetail = 'checksum match';
        } else {
            $eventType   = 'submission-failed-rollback';
            $eventDetail = 'checksum mismatch: SIP='.$sipChecksum.' depot='.$confirmedSha;
        }

        $this->triggerService->logEvent(
            null,
            (string) ($bewijs['zaakId'] ?? ''),
            $eventType,
            $eventDetail
        );

        return $bewijs;
    }//end verifyIntegrity()

    /**
     * Convenience: record a rollback against a failed submission.
     *
     * @param string $triggerId   Trigger id.
     * @param string $errorCode   Error code.
     * @param string $errorDetail Error detail.
     *
     * @return void
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function recordRollback(string $triggerId, string $errorCode, string $errorDetail): void
    {
        $this->triggerService->updateTriggerStatus($triggerId, 'gefaald');
        $this->triggerService->logEvent($triggerId, null, 'rollback-executed', 'errorCode='.$errorCode.' '.$errorDetail);
    }//end recordRollback()

    /**
     * Static error-code → corrective-action mapping.
     *
     * @param string $errorCode Error code.
     *
     * @return string
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function recommendCorrectiveAction(string $errorCode): string
    {
        return match ($errorCode) {
            'MDTO_VALIDATION_FAILED'        => 'Controleer ontbrekende of ongeldige MDTO-velden en herstart de bundel.',
            'CHECKSUM_MISMATCH'             => 'Bestandsoverdracht corrupt — herstart de overdracht.',
            'DOCUMENT_CONVERSION_FAILED'    => 'Controleer de Docudesk PDF/A-conversie en converteer de bron handmatig.',
            'E_DEPOT_CAPACITY_EXCEEDED'     => 'Neem contact op met de e-Depot beheerder voor capaciteitsuitbreiding.',
            default                          => 'Onbekende fout; controleer logregels en neem contact op met DIV.',
        };
    }//end recommendCorrectiveAction()

    /**
     * Persist an object to its configured register + schema.
     *
     * @param string               $schemaConfigKey Schema config key.
     * @param array<string, mixed> $object          Payload.
     *
     * @return array<string, mixed>
     */
    private function save(string $schemaConfigKey, array $object): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue($schemaConfigKey);
        if ($objectService === null || $register === '' || $schema === '') {
            return $object;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $object);
            if (is_array($saved) === true) {
                return $saved;
            }

            return $object;
        } catch (\Throwable $e) {
            $this->logger->error('Archief persist failed', ['key' => $schemaConfigKey, 'error' => $e->getMessage()]);
            return $object;
        }
    }//end save()
}//end class
