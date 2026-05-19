<?php

/**
 * Procest ZIP Manifest Builder
 *
 * Builds manifest.csv and informatieobjecttype-foldered ZIP archives for
 * dossier export. Streams entries so large dossiers do not load fully into
 * memory. Excludes documents the requesting user is not cleared to access.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T04
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Builds ZIP archives with manifest.csv for dossier export.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T04
 */
class ZipManifestBuilder
{

    /**
     * CSV columns written to manifest.csv inside the ZIP.
     */
    private const MANIFEST_COLUMNS = [
        'bestandsnaam',
        'titel',
        'informatieobjecttype',
        'status',
        'vertrouwelijkheidaanduiding',
        'creatiedatum',
        'auteur',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService             $settingsService The settings service
     * @param InformatieobjectAccessGuard $accessGuard     The access guard
     * @param LoggerInterface             $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly InformatieobjectAccessGuard $accessGuard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a ZIP archive for the given informatieobjecten.
     *
     * Files are organized in sub-folders named after their informatieobjecttype.
     * A manifest.csv is written to the archive root listing all included files.
     * Returns path to a temporary file the caller should stream and then delete.
     *
     * @param IUser                          $user               Requesting user (clearance filter)
     * @param array<int,array<string,mixed>> $informatieobjecten Records to include
     *
     * @return string Absolute path to the temporary ZIP file
     *
     * @throws \RuntimeException When ZIP creation fails
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T04
     */
    public function buildZip(IUser $user, array $informatieobjecten): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'zaakdossier_').'.zip';
        $zip     = new \ZipArchive();

        if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create temporary ZIP file.');
        }

        $manifestRows = [];

        foreach ($informatieobjecten as $infoObject) {
            if ($this->accessGuard->canRead(user: $user, informatieobject: $infoObject) === false) {
                continue;
            }

            $type     = $this->sanitizeFolderName(name: $infoObject['informatieobjecttype'] ?? 'overig');
            $filename = $infoObject['bestandsnaam'] ?? 'document';
            $zipPath  = $type.'/'.$filename;

            $fileId = $infoObject['fileId'] ?? null;
            if ($fileId !== null) {
                $content = $this->readFileById(fileId: (int) $fileId);
                if ($content !== null) {
                    $zip->addFromString($zipPath, $content);
                }
            }

            $manifestRows[] = array_map(
                fn(string $col) => $infoObject[$col] ?? '',
                self::MANIFEST_COLUMNS,
            );
        }//end foreach

        $zip->addFromString('manifest.csv', $this->buildCsv(rows: $manifestRows));
        $zip->close();

        return $tmpPath;
    }//end buildZip()

    /**
     * Sanitize a string for use as a folder name inside a ZIP.
     *
     * @param string $name Raw name
     *
     * @return string Safe folder name
     */
    private function sanitizeFolderName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_\. ]/', '_', $name) ?? 'overig';
    }//end sanitizeFolderName()

    /**
     * Read a Nextcloud file by its node ID.
     *
     * Returns null when the file cannot be read (e.g. permissions, missing).
     *
     * @param int $fileId Nextcloud file node ID
     *
     * @return string|null File contents or null
     */
    private function readFileById(int $fileId): ?string
    {
        try {
            $root  = \OC::$server->get(\OCP\Files\IRootFolder::class);
            $nodes = $root->getById($fileId);
            if (empty($nodes) === true) {
                return null;
            }

            $node = $nodes[0];
            if ($node instanceof \OCP\Files\File) {
                return $node->getContent();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ZipManifestBuilder: could not read file '.$fileId.': '.$e->getMessage());
        }

        return null;
    }//end readFileById()

    /**
     * Build a CSV string from rows.
     *
     * @param array<int,array<string>> $rows Data rows (header will be prepended)
     *
     * @return string CSV content
     */
    private function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::MANIFEST_COLUMNS);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            return '';
        }

        return $csv;
    }//end buildCsv()
}//end class
