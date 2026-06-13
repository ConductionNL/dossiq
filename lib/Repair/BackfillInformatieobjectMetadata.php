<?php

/**
 * Procest Backfill Informatieobject Metadata Repair Step
 *
 * Idempotent migration that converts existing dossier files into ZGW DRC
 * `informatieobject` register objects. It iterates the document storage
 * folders, and for any file that does not yet have an informatieobject it
 * creates one with sensible defaults (`status` = concept,
 * `vertrouwelijkheidaanduiding` = intern, `auteur` = file owner display name,
 * `integriteit.waarde` = SHA-256 of the file content) plus a
 * `zaakinformatieobject` join when a linking case can be determined. Files
 * that already have an informatieobject (matched by `bestandsnaam`) are
 * skipped, so a re-run is a no-op.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that back-fills informatieobject metadata for existing files.
 */
class BackfillInformatieobjectMetadata implements IRepairStep
{
    use SearchesObjects;

    /**
     * Document storage base path (mirrors ZgwDocumentService::STORAGE_BASE).
     */
    private const STORAGE_BASE = 'procest/documenten';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service (config + ObjectService).
     * @param IRootFolder     $rootFolder      Nextcloud root folder.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the repair-step display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Back-fill ZGW informatieobject metadata for existing Procest dossier files';
    }//end getName()

    /**
     * Run the repair step.
     *
     * @param IOutput $output Output sink.
     *
     * @return void
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T09
     */
    public function run(IOutput $output): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->info('Procest backfill: OpenRegister unavailable; skipping.');
            return;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
        if ($register === '' || $infoSchema === '') {
            $output->info('Procest backfill: dossier schemas not configured; skipping.');
            return;
        }

        $folder = $this->resolveStorageFolder();
        if ($folder === null) {
            $output->info('Procest backfill: storage folder absent; nothing to back-fill.');
            return;
        }

        $existing = $this->existingFilenames(objectService: $objectService, register: $register, schema: $infoSchema);

        $created = 0;
        $skipped = 0;
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder === false) {
                continue;
            }

            foreach ($node->getDirectoryListing() as $fileNode) {
                if ($fileNode instanceof File === false) {
                    continue;
                }

                $fileName = $fileNode->getName();
                if (str_starts_with($fileName, '_part_') === true) {
                    continue;
                }

                if (in_array($fileName, $existing, true) === true) {
                    $skipped++;
                    continue;
                }

                try {
                    $this->backfillFile(
                        objectService: $objectService,
                        register: $register,
                        schema: $infoSchema,
                        folderUuid: $node->getName(),
                        file: $fileNode,
                    );
                    $existing[] = $fileName;
                    $created++;
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Procest backfill: failed for '.$fileName.': '.$e->getMessage(),
                        ['app' => Application::APP_ID],
                    );
                }
            }//end foreach
        }//end foreach

        $output->info('Procest backfill: created '.$created.' informatieobject(en), skipped '.$skipped.' existing.');
    }//end run()

    /**
     * Create an informatieobject (+ join when possible) for one existing file.
     *
     * @param object $objectService The OpenRegister object service.
     * @param string $register      The register slug.
     * @param string $schema        The informatieobject schema slug.
     * @param string $folderUuid    The storing folder UUID (used as the link key).
     * @param File   $file          The Nextcloud file node.
     *
     * @return void
     */
    private function backfillFile(object $objectService, string $register, string $schema, string $folderUuid, File $file): void
    {
        $content = (string) $file->getContent();
        $owner   = $file->getOwner();
        $author  = $owner !== null ? $owner->getDisplayName() : '';

        $informatieobject = [
            'titel'                       => $file->getName(),
            'bestandsnaam'                => $file->getName(),
            'bestandsomvang'              => $file->getSize(),
            'formaat'                     => $file->getMimeType(),
            'vertrouwelijkheidaanduiding' => 'intern',
            'auteur'                      => $author,
            'status'                      => 'concept',
            'informatieobjecttype'        => '',
            'creatiedatum'                => date('Y-m-d', $file->getMTime()),
            'taal'                        => 'nld',
            'fileId'                      => $file->getId(),
            'integriteit'                 => [
                'algoritme' => 'sha256',
                'waarde'    => hash('sha256', $content),
                'datum'     => date('Y-m-d\TH:i:s'),
            ],
        ];

        $saved  = $objectService->saveObject(object: $informatieobject, register: $register, schema: $schema);
        $infoId = is_object($saved) === true ? $saved->getUuid() : '';

        $joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
        if ($joinSchema !== '' && $infoId !== '') {
            $objectService->saveObject(
                object: [
                    'zaak'                => $folderUuid,
                    'informatieobject'    => $infoId,
                    'aardRelatieWeergave' => 'Hoort bij, omgekeerd',
                    'registratiedatum'    => date('Y-m-d\TH:i:s\Z'),
                ],
                register: $register,
                schema: $joinSchema,
            );
        }
    }//end backfillFile()

    /**
     * Collect the bestandsnaam of every existing informatieobject for idempotency.
     *
     * @param object $objectService The OpenRegister object service.
     * @param string $register      The register slug.
     * @param string $schema        The informatieobject schema slug.
     *
     * @return string[] Filenames already represented by an informatieobject.
     */
    private function existingFilenames(object $objectService, string $register, string $schema): array
    {
        $rows = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 10000],
        );

        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['bestandsnaam'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }//end existingFilenames()

    /**
     * Resolve the document storage folder, or null when it does not exist.
     *
     * @return Folder|null
     */
    private function resolveStorageFolder(): ?Folder
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: 'admin');
            if ($userFolder->nodeExists(path: self::STORAGE_BASE) === false) {
                return null;
            }

            $node = $userFolder->get(path: self::STORAGE_BASE);
            if ($node instanceof Folder === true) {
                return $node;
            }
        } catch (NotFoundException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Procest backfill: cannot resolve storage folder: '.$e->getMessage());
            return null;
        }

        return null;
    }//end resolveStorageFolder()
}//end class
