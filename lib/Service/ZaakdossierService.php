<?php

/**
 * Procest Zaakdossier Service
 *
 * Orchestrates upload, link, status transitions, integrity hash, and dossier
 * listing for ZGW DRC-compliant case dossiers. Delegates file I/O to
 * OpenRegister file handlers; owns the ZGW metadata layer.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for zaakdossier (case document dossier) management.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
 */
class ZaakdossierService
{

    /**
     * Valid status transitions (from => allowed tos).
     */
    private const ALLOWED_TRANSITIONS = [
        'concept'      => ['definitief'],
        'definitief'   => ['gearchiveerd'],
        'gearchiveerd' => [],
    ];

    /**
     * Register slug for procest.
     */
    private const REGISTER = 'procest';

    /**
     * Constructor.
     *
     * @param SettingsService             $settingsService The settings service
     * @param InformatieobjectAccessGuard $accessGuard     The access guard
     * @param IUserSession                $userSession     The current user session
     * @param LoggerInterface             $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly InformatieobjectAccessGuard $accessGuard,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Upload a document and create an informatieobject + zaakinformatieobject.
     *
     * @param string              $caseId   UUID of the case
     * @param array<string,mixed> $file     File data (tmp_name, name, size, type)
     * @param array<string,mixed> $metadata ZGW metadata (titel, vertrouwelijkheidaanduiding, informatieobjecttype, etc.)
     *
     * @return array<string,mixed> Created informatieobject
     *
     * @throws \RuntimeException when file storage fails
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function uploadDocument(string $caseId, array $file, array $metadata): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister ObjectService unavailable.');
        }

        $infoObjectSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
        $joinSchema       = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $auteur = $user->getDisplayName();
        } else {
            $auteur = 'unknown';
        }

        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath !== '' && file_exists($tmpPath) === true) {
            $intHash = hash_file('sha256', $tmpPath);
        } else {
            $intHash = '';
        }

        $infoObject = array_merge(
            [
                'bestandsnaam'   => $file['name'] ?? 'document',
                'bestandsomvang' => $file['size'] ?? 0,
                'formaat'        => $file['type'] ?? 'application/octet-stream',
                'auteur'         => $auteur,
                'status'         => 'concept',
                'taal'           => 'nld',
                'creatiedatum'   => date('Y-m-d'),
                'integriteit'    => ['algoritme' => 'sha256', 'waarde' => $intHash],
            ],
            $metadata,
        );

        $savedInfoObject = $objectService->saveObject(
            register: self::REGISTER,
            schema: $infoObjectSchema,
            object: $infoObject,
        );

        $join = [
            'zaak'                => $caseId,
            'informatieobject'    => $savedInfoObject['id'],
            'registratiedatum'    => date('c'),
            'aardRelatieWeergave' => 'Hoort bij, omgekeerd',
        ];

        $objectService->saveObject(
            register: self::REGISTER,
            schema: $joinSchema,
            object: $join,
        );

        return $savedInfoObject;
    }//end uploadDocument()

    /**
     * Link an existing informatieobject to a case (deduplicates joins).
     *
     * @param string $caseId       UUID of the case
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return array<string,mixed> Created or existing join
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function linkExistingInformatieobject(string $caseId, string $infoObjectId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister ObjectService unavailable.');
        }

        $joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

        $existing = $objectService->findObjects(
            register: self::REGISTER,
            schema: $joinSchema,
            params: ['zaak' => $caseId, 'informatieobject' => $infoObjectId, '_limit' => 1],
        );

        if (empty($existing) === false) {
            return $existing[0];
        }

        return $objectService->saveObject(
            register: self::REGISTER,
            schema: $joinSchema,
            object: [
                'zaak'                => $caseId,
                'informatieobject'    => $infoObjectId,
                'registratiedatum'    => date('c'),
                'aardRelatieWeergave' => 'Hoort bij, omgekeerd',
            ],
        );
    }//end linkExistingInformatieobject()

    /**
     * Unlink an informatieobject from a case (preserves the informatieobject itself).
     *
     * @param string $caseId       UUID of the case
     * @param string $infoObjectId UUID of the informatieobject
     *
     * @return void
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function unlinkInformatieobject(string $caseId, string $infoObjectId): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister ObjectService unavailable.');
        }

        $joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

        $joins = $objectService->findObjects(
            register: self::REGISTER,
            schema: $joinSchema,
            params: ['zaak' => $caseId, 'informatieobject' => $infoObjectId],
        );

        foreach ($joins as $join) {
            $objectService->deleteObject(
                register: self::REGISTER,
                schema: $joinSchema,
                id: $join['id'],
            );
        }
    }//end unlinkInformatieobject()

    /**
     * Transition the status of an informatieobject and enforce lifecycle rules.
     *
     * @param string $infoObjectId UUID of the informatieobject
     * @param string $newStatus    Target status (concept|definitief|gearchiveerd)
     *
     * @return array<string,mixed> Updated informatieobject
     *
     * @throws \RuntimeException on invalid transition
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function transitionStatus(string $infoObjectId, string $newStatus): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister ObjectService unavailable.');
        }

        $schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

        $infoObject = $objectService->findObject(
            register: self::REGISTER,
            schema: $schema,
            id: $infoObjectId,
        );

        if ($infoObject === null) {
            throw new \RuntimeException('Informatieobject not found: '.$infoObjectId);
        }

        $currentStatus = $infoObject['status'] ?? 'concept';
        $allowed       = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (in_array($newStatus, $allowed, strict: true) === false) {
            throw new \RuntimeException(
                sprintf('Transition from %s to %s is not allowed.', $currentStatus, $newStatus)
            );
        }

        $infoObject['status'] = $newStatus;

        if ($newStatus === 'definitief') {
            $infoObject['vergrendeldOp'] = date('c');
        }

        return $objectService->saveObject(
            register: self::REGISTER,
            schema: $schema,
            object: $infoObject,
        );
    }//end transitionStatus()

    /**
     * Get all informatieobjecten for a case, grouped by informatieobjecttype.
     *
     * @param string $caseId UUID of the case
     *
     * @return array<string,array<int,array<string,mixed>>> Grouped by type slug
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function getDossierForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister ObjectService unavailable.');
        }

        $joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
        $infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

        $joins = $objectService->findObjects(
            register: self::REGISTER,
            schema: $joinSchema,
            params: ['zaak' => $caseId, '_limit' => 500],
        );

        $user    = $this->userSession->getUser();
        $grouped = [];

        foreach ($joins as $join) {
            $infoId = $join['informatieobject'] ?? null;
            if ($infoId === null) {
                continue;
            }

            $infoObject = $objectService->findObject(
                register: self::REGISTER,
                schema: $infoSchema,
                id: (string) $infoId,
            );

            if ($infoObject === null) {
                continue;
            }

            if ($user !== null && $this->accessGuard->canRead(user: $user, informatieobject: $infoObject) === false) {
                continue;
            }

            $type = $infoObject['informatieobjecttype'] ?? 'overig';
            $grouped[$type][] = $infoObject;
        }//end foreach

        return $grouped;
    }//end getDossierForCase()

    /**
     * Bulk transition multiple informatieobjecten to a new status.
     *
     * @param array<string> $infoObjectIds List of UUIDs
     * @param string        $newStatus     Target status
     *
     * @return array<string,mixed> Per-id results (id => success/error)
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T02
     */
    public function bulkTransitionStatus(array $infoObjectIds, string $newStatus): array
    {
        $results = [];

        foreach ($infoObjectIds as $id) {
            try {
                $this->transitionStatus(infoObjectId: $id, newStatus: $newStatus);
                $results[$id] = ['success' => true];
            } catch (\RuntimeException $e) {
                $results[$id] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }//end bulkTransitionStatus()
}//end class
