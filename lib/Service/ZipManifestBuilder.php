<?php

/**
 * Procest Zip Manifest Builder
 *
 * Builds a ZIP export of a case dossier: documents are organised into
 * per-informatieobjecttype sub-folders and accompanied by a `manifest.csv`
 * describing every included document. Documents above the caller's clearance
 * are excluded. Entries are added one at a time (each file's content is read,
 * written and released before the next), so a large dossier never loads all
 * file contents into memory simultaneously — mirroring the streaming intent of
 * OpenRegister's `FilePublishingHandler::createObjectFilesZip()` while reusing
 * the app's established `\ZipArchive` convention.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Builds a manifest-bearing, type-foldered ZIP export of a dossier.
 */
class ZipManifestBuilder
{
    /**
     * manifest.csv column order.
     */
    public const MANIFEST_COLUMNS = [
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
     * @param ZgwDocumentService          $documentService Binary file storage service.
     * @param InformatieobjectAccessGuard $accessGuard     Confidentiality guard.
     * @param LoggerInterface             $logger          Logger.
     */
    public function __construct(
        private readonly ZgwDocumentService $documentService,
        private readonly InformatieobjectAccessGuard $accessGuard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a CSV manifest string for a list of informatieobjecten.
     *
     * @param array<int, array<string, mixed>> $documents The documents to describe.
     *
     * @return string The manifest.csv content.
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T04
     */
    public function buildManifest(array $documents): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::MANIFEST_COLUMNS);
        foreach ($documents as $doc) {
            $row = [];
            foreach (self::MANIFEST_COLUMNS as $column) {
                $row[] = (string) ($doc[$column] ?? '');
            }

            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end buildManifest()

    /**
     * Filter a document list to those the user is cleared to read.
     *
     * @param IUser|null                       $user      The caller, or null (treated as no extra filtering).
     * @param array<int, array<string, mixed>> $documents Candidate documents.
     *
     * @return array<int, array<string, mixed>> The clearance-filtered list.
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T04
     */
    public function filterByClearance(?IUser $user, array $documents): array
    {
        if ($user === null) {
            return array_values($documents);
        }

        return array_values($this->accessGuard->filterDossierForUser(user: $user, informatieobjecten: $documents));
    }//end filterByClearance()

    /**
     * Build a ZIP archive at the given path for the supplied documents.
     *
     * Documents above the caller's clearance are excluded before any file is
     * read. The archive contains one sub-folder per informatieobjecttype (when
     * $subfolderPerType is true) plus a `manifest.csv` at the root.
     *
     * @param string                           $targetPath       Filesystem path to write the ZIP to.
     * @param IUser|null                       $user             The caller (for clearance filtering).
     * @param array<int, array<string, mixed>> $documents        Candidate documents.
     * @param bool                             $subfolderPerType Organise into per-type sub-folders.
     *
     * @return array<string, mixed> Result with `path`, `included` count and `excluded` count.
     *
     * @throws \RuntimeException When the ZIP archive cannot be created.
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#T04
     */
    public function buildZip(string $targetPath, ?IUser $user, array $documents, bool $subfolderPerType=true): array
    {
        $candidateCount = count($documents);
        $included       = $this->filterByClearance(user: $user, documents: $documents);
        $excluded       = ($candidateCount - count($included));

        $zip = new \ZipArchive();
        if ($zip->open($targetPath, (\ZipArchive::CREATE | \ZipArchive::OVERWRITE)) !== true) {
            throw new \RuntimeException('Could not create ZIP archive at '.$targetPath);
        }

        // manifest.csv at the archive root.
        $zip->addFromString('manifest.csv', $this->buildManifest($included));

        $usedNames = [];
        foreach ($included as $doc) {
            $infoId   = (string) ($doc['id'] ?? ($doc['uuid'] ?? ''));
            $fileName = (string) ($doc['bestandsnaam'] ?? '');
            if ($infoId === '' || $fileName === '') {
                continue;
            }

            $entryName = $this->buildEntryName(
                doc: $doc,
                fileName: $fileName,
                subfolderPerType: $subfolderPerType,
                usedNames: $usedNames,
            );

            try {
                // Read one file at a time; content is released before the next iteration.
                $content = $this->documentService->getContent(uuid: $infoId, fileName: $fileName);
                $zip->addFromString($entryName, $content);
                unset($content);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Procest dossier ZIP: skipped unreadable file '.$fileName.' ('.$e->getMessage().')'
                );
            }
        }//end foreach

        $zip->close();

        return [
            'path'     => $targetPath,
            'included' => count($included),
            'excluded' => $excluded,
        ];
    }//end buildZip()

    /**
     * Compute the unique in-archive entry name for a document.
     *
     * @param array<string, mixed> $doc              The document record.
     * @param string               $fileName         The base filename.
     * @param bool                 $subfolderPerType Whether to prefix with the type folder.
     * @param array<string, int>   $usedNames        Reference of already-used names for de-duplication.
     *
     * @return string The unique entry name.
     */
    private function buildEntryName(array $doc, string $fileName, bool $subfolderPerType, array &$usedNames): string
    {
        $prefix = '';
        if ($subfolderPerType === true) {
            $type   = (string) ($doc['informatieobjecttype'] ?? 'onbekend');
            $prefix = $this->sanitizeSegment($type).'/';
        }

        $entry = $prefix.$this->sanitizeSegment($fileName, true);

        if (isset($usedNames[$entry]) === true) {
            $usedNames[$entry]++;
            $dot       = strrpos($fileName, '.');
            $base      = $dot === false ? $fileName : substr($fileName, 0, $dot);
            $extension = $dot === false ? '' : substr($fileName, $dot);
            $entry     = $prefix.$this->sanitizeSegment($base, true).'_'.$usedNames[$entry].$extension;
        } else {
            $usedNames[$entry] = 0;
        }

        return $entry;
    }//end buildEntryName()

    /**
     * Sanitise a path segment for safe inclusion in a ZIP entry name.
     *
     * @param string $segment  The raw segment.
     * @param bool   $keepDots Whether to preserve dots (for filenames with extensions).
     *
     * @return string The sanitised segment.
     */
    private function sanitizeSegment(string $segment, bool $keepDots=false): string
    {
        $segment = str_replace(['/', '\\', "\0"], '_', $segment);
        $segment = trim($segment);
        if ($keepDots === false) {
            $segment = str_replace('.', '_', $segment);
        }

        if ($segment === '' || $segment === '.' || $segment === '..') {
            return 'onbekend';
        }

        return $segment;
    }//end sanitizeSegment()
}//end class
