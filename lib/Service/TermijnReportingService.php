<?php

/**
 * Procest TermijnReportingService.
 *
 * Two report families:
 *   - Quarterly KPI report (per zaaktype): totaal, binnen-termijn %,
 *     gemiddelde doorlooptijd, verlengingen, overschrijdingen,
 *     ingebrekestellingen, dwangsom-total.
 *   - Annual dwangsom audit report: per-uitbetaling row
 *     (zaak-ref, zaaktype, ingebrekestelling-datum, beschikking-datum,
 *     bedrag, betaal-datum, betalings-referentie, status).
 *
 * Plus dashboard KPI snapshot.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Quarterly KPI + annual dwangsom audit + dashboard KPI reports.
 */
class TermijnReportingService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * Generate a quarterly KPI report.
     *
     * @param string      $periode  Period (YYYY-Qn, e.g. "2026-Q2").
     * @param string|null $afdeling Optional department filter.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function generateQuarterlyReport(string $periode, ?string $afdeling=null): array
    {
        $bounds = $this->resolveQuarter(periode: $periode);
        $rows   = $this->listInstances(from: $bounds['from'], until: $bounds['until']);

        $byType = [];
        foreach ($rows as $row) {
            $type = (string) ($row['zaaktype'] ?? 'onbekend');
            if ($afdeling !== null && (string) ($row['afdeling'] ?? '') !== $afdeling) {
                continue;
            }

            $byType[$type] ??= [
                'totaal'              => 0,
                'binnenTermijn'       => 0,
                'doorlooptijdenDagen' => [],
                'verlengingen'        => 0,
                'overschrijdingen'    => 0,
                'ingebrekestellingen' => 0,
                'dwangsomTotalCents'  => 0,
            ];

            $byType[$type]['totaal']++;
            $status = (string) ($row['status'] ?? '');
            if ($status === 'voltooid') {
                $byType[$type]['binnenTermijn']++;
            }

            if ($status === 'overschreden') {
                $byType[$type]['overschrijdingen']++;
            }

            if ((int) ($row['aantalVerlengingen'] ?? 0) > 0) {
                $byType[$type]['verlengingen']++;
            }

            $start = (string) ($row['startDatum'] ?? '');
            $eind  = (string) ($row['einddatumActueel'] ?? '');
            if ($start !== '' && $eind !== '') {
                $startD = new DateTimeImmutable(substr($start, 0, 10));
                $eindD  = new DateTimeImmutable($eind);
                $byType[$type]['doorlooptijdenDagen'][] = (int) $startD->diff($eindD)->days;
            }
        }//end foreach

        // Reduce per-type aggregates.
        $perType = [];
        foreach ($byType as $type => $b) {
            // $byType entries are only created when a row is counted, so
            // 'totaal' is always >= 1 here.
            $totaal    = $b['totaal'];
            $binnenPct = round(($b['binnenTermijn'] / $totaal) * 100, 1);

            $aantalDoorlooptijden = count($b['doorlooptijdenDagen']);
            if ($aantalDoorlooptijden > 0) {
                $avgDur = round(array_sum($b['doorlooptijdenDagen']) / $aantalDoorlooptijden, 1);
            } else {
                $avgDur = 0.0;
            }

            $perType[$type] = [
                'totaal'                      => $totaal,
                'binnenTermijnPct'            => $binnenPct,
                'gemiddeldeDoorlooptijdDagen' => $avgDur,
                'verlengingen'                => $b['verlengingen'],
                'overschrijdingen'            => $b['overschrijdingen'],
                'ingebrekestellingen'         => $b['ingebrekestellingen'],
                'dwangsomTotalCents'          => $b['dwangsomTotalCents'],
            ];
        }//end foreach

        return [
            'periode'  => $periode,
            'afdeling' => $afdeling,
            'from'     => $bounds['from'],
            'until'    => $bounds['until'],
            'perType'  => $perType,
            'metadata' => [
                'generatedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'rowsScanned' => count($rows),
            ],
        ];
    }//end generateQuarterlyReport()

    /**
     * Generate an annual dwangsom audit report.
     *
     * @param int $jaar Year.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function generateDwangsomAuditReport(int $jaar): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $uSchema       = (string) $this->settingsService->getConfigValue('dwangsom_uitbetaling_schema');
        if ($objectService === null || $register === '' || $uSchema === '') {
            return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0]];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $uSchema, filters: []);
        } catch (\Throwable $e) {
            return ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0]];
        }

        $jaarPrefix = (string) $jaar;
        $outRows    = [];
        $totaal     = 0;
        $warnings   = [];

        foreach ($rows as $row) {
            $betaal = (string) ($row['werkelijkeBetaaldatum'] ?? '');
            if (str_starts_with($betaal, $jaarPrefix) === false) {
                continue;
            }

            $bedrag  = (int) ($row['bedrag'] ?? 0);
            $totaal += $bedrag;

            if (($row['betalingsreferentie'] ?? '') === '') {
                $warnings[] = 'Missing betalingsreferentie for '.((string) ($row['referentie'] ?? ''));
            }

            $outRows[] = [
                'referentie'            => (string) ($row['referentie'] ?? ''),
                'bedragCents'           => $bedrag,
                'werkelijkeBetaaldatum' => $betaal,
                'betalingsreferentie'   => (string) ($row['betalingsreferentie'] ?? ''),
                'status'                => (string) ($row['status'] ?? ''),
                'wettelijkeGrondslag'   => (string) ($row['wettelijkeGrondslag'] ?? ''),
                'iban'                  => (string) ($row['iban'] ?? ''),
            ];
        }//end foreach

        return [
            'jaar'     => $jaar,
            'rows'     => $outRows,
            'summary'  => ['count' => count($outRows), 'totalCents' => $totaal],
            'warnings' => $warnings,
        ];
    }//end generateDwangsomAuditReport()

    /**
     * Compute a snapshot KPI summary for the dashboard widget.
     *
     * @param array<string, mixed> $filters Optional filters (afdeling, zaaktype).
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function getTermijnKpi(array $filters=[]): array
    {
        $rows = $this->listInstances(from: '1970-01-01', until: '2999-12-31');

        $total     = 0;
        $within    = 0;
        $overrun   = 0;
        $durations = [];
        $dwTotal   = 0;
        foreach ($rows as $row) {
            if (isset($filters['zaaktype']) === true && (string) ($row['zaaktype'] ?? '') !== $filters['zaaktype']) {
                continue;
            }

            $total++;
            $status = (string) ($row['status'] ?? '');
            if ($status === 'voltooid') {
                $within++;
            }

            if ($status === 'overschreden') {
                $overrun++;
            }

            $start = (string) ($row['startDatum'] ?? '');
            $eind  = (string) ($row['einddatumActueel'] ?? '');
            if ($start !== '' && $eind !== '') {
                $durations[] = (int) (new DateTimeImmutable(substr($start, 0, 10)))->diff(new DateTimeImmutable($eind))->days;
            }
        }//end foreach

        if ($total > 0) {
            $withinTermijnPercent = round(($within / $total) * 100, 1);
        } else {
            $withinTermijnPercent = 0.0;
        }

        $aantalDuraties = count($durations);
        if ($aantalDuraties > 0) {
            $avgDurationDays = round(array_sum($durations) / $aantalDuraties, 1);
        } else {
            $avgDurationDays = 0.0;
        }

        return [
            'totalZaken'           => $total,
            'withinTermijnPercent' => $withinTermijnPercent,
            'avgDurationDays'      => $avgDurationDays,
            'overrunCount'         => $overrun,
            'dwangsomTotalCents'   => $dwTotal,
            'lastUpdated'          => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];
    }//end getTermijnKpi()

    /**
     * Generate a CSV for a quarterly report.
     *
     * @param array<string, mixed> $report Report.
     *
     * @return string
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
     */
    public function quarterlyReportAsCsv(array $report): string
    {
        $header = [
            'zaaktype',
            'totaal',
            'binnenTermijnPct',
            'gemiddeldeDoorlooptijdDagen',
            'verlengingen',
            'overschrijdingen',
            'ingebrekestellingen',
            'dwangsomTotalCents',
        ];
        $lines  = [implode(',', $header)];
        foreach ((array) ($report['perType'] ?? []) as $type => $row) {
            $line    = [
                $type,
                (string) ($row['totaal'] ?? 0),
                (string) ($row['binnenTermijnPct'] ?? 0),
                (string) ($row['gemiddeldeDoorlooptijdDagen'] ?? 0),
                (string) ($row['verlengingen'] ?? 0),
                (string) ($row['overschrijdingen'] ?? 0),
                (string) ($row['ingebrekestellingen'] ?? 0),
                (string) ($row['dwangsomTotalCents'] ?? 0),
            ];
            $lines[] = implode(',', $line);
        }

        return implode("\n", $lines);
    }//end quarterlyReportAsCsv()

    /**
     * Resolve a quarter spec (YYYY-Qn) to its from/until date bounds.
     *
     * @param string $periode Period (YYYY-Qn).
     *
     * @return array{from:string,until:string}
     */
    private function resolveQuarter(string $periode): array
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $periode, $matches) !== 1) {
            throw new RuntimeException('Invalid periode (expected YYYY-Qn): '.$periode);
        }

        $year    = (int) $matches[1];
        $quarter = (int) $matches[2];
        $startM  = (($quarter - 1) * 3) + 1;
        $endM    = $startM + 2;
        $from    = sprintf('%04d-%02d-01', $year, $startM);
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endM)))->format('t');
        $until   = sprintf('%04d-%02d-%02d', $year, $endM, $lastDay);
        return ['from' => $from, 'until' => $until];
    }//end resolveQuarter()

    /**
     * List termijn instances whose start date falls within the given bounds.
     *
     * @param string $from  YYYY-MM-DD.
     * @param string $until YYYY-MM-DD.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listInstances(string $from, string $until): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('termijn_instance_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $start = substr((string) ($row['startDatum'] ?? ''), 0, 10);
            if ($start === '' || ($start >= $from && $start <= $until) === false) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }//end listInstances()
}//end class
