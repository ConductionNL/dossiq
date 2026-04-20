<?php

/**
 * Procest Leges Export Service
 *
 * Service for exporting legesberekeningen to financial systems.
 * Supports CSV, ASCII flat file, and XML export formats.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for exporting fee calculations to financial systems.
 *
 * Generates export files in CSV, ASCII, or XML format containing
 * NAW-gegevens, BSN/KvK, zaaknummer, leges artikelnummer,
 * omschrijving, bedrag, and datum beschikking.
 *
 * @psalm-suppress UnusedClass
 */
class LegesExportService
{
    /**
     * Export format: CSV.
     */
    public const FORMAT_CSV = 'csv';

    /**
     * Export format: ASCII flat file.
     */
    public const FORMAT_ASCII = 'ascii';

    /**
     * Export format: XML (StUF-FIN compatible).
     */
    public const FORMAT_XML = 'xml';

    /**
     * Supported export formats.
     *
     * @var string[]
     */
    public const SUPPORTED_FORMATS = [
        self::FORMAT_CSV,
        self::FORMAT_ASCII,
        self::FORMAT_XML,
    ];

    /**
     * CSV column headers.
     *
     * @var string[]
     */
    private const CSV_HEADERS = [
        'zaaknummer',
        'bsn_kvk',
        'naam',
        'adres',
        'artikelnummer',
        'omschrijving',
        'bedrag',
        'datum_beschikking',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Export berekeningen to the specified format.
     *
     * @param array<int, array<string, mixed>> $berekeningen The definitieve berekeningen to export.
     * @param string                           $format       The export format (csv, ascii, xml).
     *
     * @return array{content: string, filename: string, contentType: string}
     *
     * @throws \InvalidArgumentException If format is not supported.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function export(array $berekeningen, string $format=self::FORMAT_CSV): array
    {
        if (in_array($format, self::SUPPORTED_FORMATS, true) === false) {
            throw new \InvalidArgumentException(
                'Unsupported export format: '.$format.'. Supported: '.implode(', ', self::SUPPORTED_FORMATS)
            );
        }

        $this->logger->info(
            'Exporting {count} legesberekeningen in {format} format',
            ['count' => count($berekeningen), 'format' => $format]
        );

        return match ($format) {
            self::FORMAT_CSV => $this->exportCSV(berekeningen: $berekeningen),
            self::FORMAT_ASCII => $this->exportASCII(berekeningen: $berekeningen),
            self::FORMAT_XML => $this->exportXML(berekeningen: $berekeningen),
        };
    }//end export()

    /**
     * Export berekeningen as CSV.
     *
     * @param array<int, array<string, mixed>> $berekeningen The berekeningen.
     *
     * @return array{content: string, filename: string, contentType: string}
     */
    private function exportCSV(array $berekeningen): array
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException('Failed to create temp stream for CSV export');
        }

        // Write BOM for Excel compatibility.
        fwrite($output, "\xEF\xBB\xBF");

        // Write headers.
        fputcsv($output, self::CSV_HEADERS, ';');

        foreach ($berekeningen as $berekening) {
            $rows = $this->flattenBerekening(berekening: $berekening);
            foreach ($rows as $row) {
                fputcsv($output, $row, ';');
            }
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        $date = (new \DateTimeImmutable())->format('Y-m-d');

        if ($content !== false) {
            $contentString = $content;
        } else {
            $contentString = '';
        }

        return [
            'content'     => $contentString,
            'filename'    => "leges-export-{$date}.csv",
            'contentType' => 'text/csv; charset=utf-8',
        ];
    }//end exportCSV()

    /**
     * Export berekeningen as ASCII flat file.
     *
     * @param array<int, array<string, mixed>> $berekeningen The berekeningen.
     *
     * @return array{content: string, filename: string, contentType: string}
     */
    private function exportASCII(array $berekeningen): array
    {
        $lines = [];

        // Header line.
        $lines[] = sprintf(
            'H|LEGES|%s|%d',
            (new \DateTimeImmutable())->format('Ymd'),
            count($berekeningen)
        );

        foreach ($berekeningen as $berekening) {
            $rows = $this->flattenBerekening(berekening: $berekening);
            foreach ($rows as $row) {
                $lines[] = 'D|'.implode('|', $row);
            }
        }

        // Footer line.
        $total   = array_sum(array_column($berekeningen, 'total'));
        $lines[] = sprintf('F|%d|%.2f', count($berekeningen), $total);

        $date = (new \DateTimeImmutable())->format('Y-m-d');

        return [
            'content'     => implode("\r\n", $lines),
            'filename'    => "leges-export-{$date}.txt",
            'contentType' => 'text/plain; charset=utf-8',
        ];
    }//end exportASCII()

    /**
     * Export berekeningen as XML (StUF-FIN compatible structure).
     *
     * @param array<int, array<string, mixed>> $berekeningen The berekeningen.
     *
     * @return array{content: string, filename: string, contentType: string}
     */
    private function exportXML(array $berekeningen): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('legesExport');
        $root->setAttribute('exportDatum', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $root->setAttribute('aantalRecords', (string) count($berekeningen));
        $dom->appendChild($root);

        foreach ($berekeningen as $berekening) {
            $berekeningEl = $dom->createElement('berekening');
            $berekeningEl->setAttribute('zaaknummer', (string) ($berekening['zaaknummer'] ?? ''));

            $this->addXmlElement(dom: $dom, parent: $berekeningEl, name: 'bsnKvk', value: (string) ($berekening['bsnKvk'] ?? ''));
            $this->addXmlElement(dom: $dom, parent: $berekeningEl, name: 'naam', value: (string) ($berekening['naam'] ?? ''));
            $this->addXmlElement(
                dom: $dom,
                parent: $berekeningEl,
                name: 'totaalBedrag',
                value: number_format($berekening['total'] ?? 0.0, 2, '.', '')
            );
            $this->addXmlElement(dom: $dom, parent: $berekeningEl, name: 'datumBeschikking', value: (string) ($berekening['datumBeschikking'] ?? ''));

            $breakdown = $berekening['breakdown'] ?? [];
            foreach ($breakdown as $regel) {
                $regelEl = $dom->createElement('regel');
                $this->addXmlElement(dom: $dom, parent: $regelEl, name: 'artikelnummer', value: (string) ($regel['artikel'] ?? ''));
                $this->addXmlElement(dom: $dom, parent: $regelEl, name: 'omschrijving', value: (string) ($regel['description'] ?? ''));
                $this->addXmlElement(dom: $dom, parent: $regelEl, name: 'bedrag', value: number_format($regel['amount'] ?? 0.0, 2, '.', ''));
                $berekeningEl->appendChild($regelEl);
            }

            $root->appendChild($berekeningEl);
        }//end foreach

        $content = $dom->saveXML();
        $date    = (new \DateTimeImmutable())->format('Y-m-d');

        if ($content !== false) {
            $contentString = $content;
        } else {
            $contentString = '';
        }

        return [
            'content'     => $contentString,
            'filename'    => "leges-export-{$date}.xml",
            'contentType' => 'application/xml; charset=utf-8',
        ];
    }//end exportXML()

    /**
     * Flatten a berekening into export rows (one row per artikel in breakdown).
     *
     * @param array<string, mixed> $berekening The berekening data.
     *
     * @return array<int, string[]> Array of row arrays.
     */
    private function flattenBerekening(array $berekening): array
    {
        $rows      = [];
        $breakdown = $berekening['breakdown'] ?? [];

        if (empty($breakdown) === true) {
            $rows[] = [
                (string) ($berekening['zaaknummer'] ?? ''),
                (string) ($berekening['bsnKvk'] ?? ''),
                (string) ($berekening['naam'] ?? ''),
                (string) ($berekening['adres'] ?? ''),
                '',
                'Totaal',
                number_format($berekening['total'] ?? 0.0, 2, '.', ''),
                (string) ($berekening['datumBeschikking'] ?? ''),
            ];
        } else {
            foreach ($breakdown as $regel) {
                $rows[] = [
                    (string) ($berekening['zaaknummer'] ?? ''),
                    (string) ($berekening['bsnKvk'] ?? ''),
                    (string) ($berekening['naam'] ?? ''),
                    (string) ($berekening['adres'] ?? ''),
                    (string) ($regel['artikel'] ?? ''),
                    (string) ($regel['description'] ?? ''),
                    number_format($regel['amount'] ?? 0.0, 2, '.', ''),
                    (string) ($berekening['datumBeschikking'] ?? ''),
                ];
            }
        }//end if

        return $rows;
    }//end flattenBerekening()

    /**
     * Add a text element to an XML parent.
     *
     * @param \DOMDocument $dom    The DOM document.
     * @param \DOMElement  $parent The parent element.
     * @param string       $name   The element name.
     * @param string       $value  The text value.
     *
     * @return void
     */
    private function addXmlElement(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }//end addXmlElement()
}//end class
