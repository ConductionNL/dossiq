<?php

/**
 * Procest Beroep Dossier Export.
 *
 * Produces the export plan for a beroep dossier destined for the
 * bestuursrechter (administrative court). It delegates document
 * gathering and ordering to {@see DossierCompiler}, then assigns each
 * document a stable, sequentially numbered export filename
 * (`01-primair-besluit.pdf`, `02-bezwaarschrift.pdf`, ...) following the
 * AWB-conventional dossier order.
 *
 * The plan it returns is the deterministic, fully testable core of the
 * export. The actual byte-level ZIP assembly (streaming file content out
 * of Nextcloud Files into a `ZipResponse`) is performed by the
 * controller against a live instance — this service contains no file IO,
 * so it is unit-testable without a running Nextcloud.
 *
 * When docudesk is available a merged PDF can additionally be produced
 * from the same plan; the ZIP/plan is always the baseline regardless.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds the ordered, numbered export plan for a beroep dossier.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class BeroepDossierExport
{
    /**
     * Constructor.
     *
     * @param DossierCompiler $dossierCompiler The dossier compiler.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly DossierCompiler $dossierCompiler,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the export plan for a beroep (or bezwaar) case.
     *
     * @param string $caseId UUID of the beroep case.
     *
     * @return array{
     *     case: string,
     *     documentCount: int,
     *     entries: array<int, array{
     *         sequence: int,
     *         filename: string,
     *         title: string,
     *         source: string,
     *         sourceCase: string
     *     }>
     * } The deterministic export plan.
     *
     * @throws RuntimeException When the dossier cannot be compiled.
     *
     * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
     */
    public function buildPlan(string $caseId): array
    {
        $documents = $this->dossierCompiler->compile(caseId: $caseId);

        $entries  = [];
        $sequence = 0;
        foreach ($documents as $document) {
            $sequence++;

            $title  = trim((string) ($document['title'] ?? 'document'));
            $source = trim((string) ($document['document'] ?? ''));

            $displayTitle = 'document';
            if ($title !== '') {
                $displayTitle = $title;
            }

            $entries[] = [
                'sequence'   => $sequence,
                'filename'   => $this->buildFilename(sequence: $sequence, title: $title, source: $source),
                'title'      => $displayTitle,
                'source'     => $source,
                'sourceCase' => (string) ($document['_sourceCase'] ?? ''),
            ];
        }

        if ($entries === []) {
            $this->logger->info(
                'BeroepDossierExport: dossier export requested for a case with no documents',
                ['case' => $caseId]
            );
        }

        return [
            'case'          => $caseId,
            'documentCount' => count($entries),
            'entries'       => $entries,
        ];
    }//end buildPlan()

    /**
     * Build a stable, sequentially numbered export filename.
     *
     * The sequence is zero-padded to two digits; the title is slugified
     * and the original file extension (when present on the source URI) is
     * preserved, defaulting to `.pdf` for court submission.
     *
     * @param int    $sequence The 1-based export sequence.
     * @param string $title    The document title.
     * @param string $source   The source document URI.
     *
     * @return string The export filename, e.g. `01-primair-besluit.pdf`.
     */
    private function buildFilename(int $sequence, string $title, string $source): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'document';
        }

        $extension = $this->extractExtension(source: $source);

        return sprintf('%02d-%s.%s', $sequence, $slug, $extension);
    }//end buildFilename()

    /**
     * Extract a file extension from a source URI, defaulting to pdf.
     *
     * @param string $source The source URI.
     *
     * @return string The (lower-case, alphanumeric) extension.
     */
    private function extractExtension(string $source): string
    {
        if ($source === '') {
            return 'pdf';
        }

        $path      = (string) (parse_url($source, PHP_URL_PATH) ?? $source);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        if ($extension === '') {
            return 'pdf';
        }

        return $extension;
    }//end extractExtension()
}//end class
