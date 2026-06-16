<?php

/**
 * Procest BagItBundlerService.
 *
 * Builds an in-memory BagIt 1.0 bundle (RFC 8493) for a SipBundel:
 *
 *   - bagit.txt with BagIt-Version 1.0 + Tag-File-Character-Encoding UTF-8
 *   - bag-info.txt with Source-Organization, Bagging-Date, Payload-Oxum
 *   - data/metadata.xml + data/<documents>
 *   - manifest-sha256.txt with SHA-256 per payload file
 *
 * Returns the canonical content as an array (suitable for passing to a
 * channel adapter for streaming upload). Real on-disk tar.gz packaging
 * is intentionally out of scope for this slice.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * BagIt 1.0 bundler.
 */
class BagItBundlerService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the BagIt content for a SipBundel.
     *
     * @param array<string, mixed> $sipBundel SipBundel row.
     *
     * @return array{files:array<string, string>, manifestChecksum:string, payloadOxum:string}
     *
     * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
     */
    public function buildBagIt(array $sipBundel): array
    {
        $files = [];
        $payloadTotalBytes = 0;
        $payloadCount = 0;

        // Payload: metadata.xml.
        $metadataXml = (string) ($sipBundel['metadataXml'] ?? '');
        $files['data/metadata.xml'] = $metadataXml;
        $payloadTotalBytes += strlen($metadataXml);
        $payloadCount++;

        // Payload: documents (use document.content if present, else placeholder).
        foreach ((array) ($sipBundel['documents'] ?? []) as $doc) {
            if (is_array($doc) === false) {
                continue;
            }
            $name    = (string) ($doc['name'] ?? ('document-'.$payloadCount));
            $content = (string) ($doc['content'] ?? '');
            $files['data/'.$name] = $content;
            $payloadTotalBytes += strlen($content);
            $payloadCount++;
        }

        // Manifest.
        $manifestLines = [];
        $payloadFiles = [];
        foreach ($files as $relPath => $content) {
            if (str_starts_with($relPath, 'data/') === true) {
                $sha = hash('sha256', $content);
                $manifestLines[] = $sha.'  '.$relPath;
                $payloadFiles[$relPath] = $sha;
            }
        }
        sort($manifestLines);
        $manifest = implode("\n", $manifestLines)."\n";
        $manifestChecksum = hash('sha256', $manifest);

        // Tag files.
        $files['bagit.txt'] = "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n";
        $payloadOxum = $payloadTotalBytes.'.'.$payloadCount;
        $files['bag-info.txt'] = "Source-Organization: Procest\n"
            ."Bagging-Date: ".(new DateTimeImmutable())->format('Y-m-d')."\n"
            ."Payload-Oxum: ".$payloadOxum."\n";
        $files['manifest-sha256.txt'] = $manifest;

        return [
            'files'            => $files,
            'manifestChecksum' => $manifestChecksum,
            'payloadOxum'      => $payloadOxum,
        ];
    }//end buildBagIt()

    /**
     * Compute a SHA-256 checksum hex string for a string.
     *
     * @param string $content Content.
     *
     * @return string
     *
     * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
     */
    public function computeChecksum(string $content): string
    {
        return hash('sha256', $content);
    }//end computeChecksum()
}//end class
