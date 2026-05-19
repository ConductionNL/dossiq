<?php

/**
 * Procest Backfill Informatieobject Metadata Migration
 *
 * Repair step: converts existing object-linked files to ZGW informatieobject
 * metadata records. Creates informatieobject entries with sensible defaults
 * and zaakinformatieobject join records for already-linked files. Idempotent.
 *
 * @category Migration
 * @package  OCA\Procest\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T09
 */

declare(strict_types=1);

namespace OCA\Procest\Migration;

use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Repair step: back-fill existing files with ZGW informatieobject metadata.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T09
 */
class BackfillInformatieobjectMetadata implements IRepairStep
{

    /**
     * Register slug for procest.
     */
    private const REGISTER = 'procest';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the name of this repair step.
     *
     * @return string Step name
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T09
     */
    public function getName(): string
    {
        return 'Procest: back-fill informatieobject metadata for existing dossier files';
    }//end getName()

    /**
     * Run the repair step.
     *
     * Iterates existing case documents, creates informatieobject records
     * with sensible defaults, and links them via zaakinformatieobject.
     * Safe to run multiple times (idempotent: skips already-migrated files).
     *
     * @param IOutput $output Migration output interface
     *
     * @return void
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T09
     */
    public function run(IOutput $output): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            $output->warning('BackfillInformatieobjectMetadata: OpenRegister unavailable, skipping.');
            return;
        }

        $caseDocSchema = $this->settingsService->getConfigValue('case_document_schema');
        $infoSchema    = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
        $joinSchema    = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

        if ($caseDocSchema === '' || $infoSchema === '' || $joinSchema === '') {
            $output->warning('BackfillInformatieobjectMetadata: schemas not configured, skipping.');
            return;
        }

        $offset    = 0;
        $batchSize = 50;
        $migrated  = 0;
        $skipped   = 0;

        do {
            $caseDocs = $objectService->findObjects(
                register: self::REGISTER,
                schema: $caseDocSchema,
                params: ['_limit' => $batchSize, '_offset' => $offset],
            );

            foreach ($caseDocs as $caseDoc) {
                $fileId = $caseDoc['fileId'] ?? null;
                $caseId = $caseDoc['case'] ?? null;

                if ($fileId === null || $caseId === null) {
                    $skipped++;
                    continue;
                }

                $existing = $objectService->findObjects(
                    register: self::REGISTER,
                    schema: $infoSchema,
                    params: ['fileId' => $fileId, '_limit' => 1],
                );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $bestandsnaam = $caseDoc['bestandsnaam'] ?? $caseDoc['filename'] ?? 'document';
                $integriteit  = ['algoritme' => 'sha256', 'waarde' => ''];

                $infoObject = $objectService->saveObject(
                    register: self::REGISTER,
                    schema: $infoSchema,
                    object: [
                        'titel'                       => $caseDoc['titel'] ?? $bestandsnaam,
                        'bestandsnaam'                => $bestandsnaam,
                        'bestandsomvang'              => $caseDoc['bestandsomvang'] ?? 0,
                        'formaat'                     => $caseDoc['formaat'] ?? 'application/octet-stream',
                        'vertrouwelijkheidaanduiding' => 'intern',
                        'auteur'                      => $caseDoc['auteur'] ?? 'migrated',
                        'status'                      => 'concept',
                        'taal'                        => 'nld',
                        'creatiedatum'                => date('Y-m-d'),
                        'integriteit'                 => $integriteit,
                        'fileId'                      => (int) $fileId,
                    ],
                );

                $objectService->saveObject(
                    register: self::REGISTER,
                    schema: $joinSchema,
                    object: [
                        'zaak'                => $caseId,
                        'informatieobject'    => $infoObject['id'],
                        'registratiedatum'    => date('c'),
                        'aardRelatieWeergave' => 'Hoort bij, omgekeerd',
                    ],
                );

                $migrated++;
            }//end foreach

            $offset += $batchSize;
        } while (count($caseDocs) === $batchSize);

        $output->info(
            sprintf(
                'BackfillInformatieobjectMetadata: migrated %d records, skipped %d.',
                $migrated,
                $skipped,
            )
        );
    }//end run()
}//end class
