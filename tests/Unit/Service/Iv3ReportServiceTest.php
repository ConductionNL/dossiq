<?php

/**
 * Iv3ReportService Unit Tests
 *
 * Covers the quarterly IV3 aggregation matrix: single case/single taakveld,
 * multiple taakvelden kept separate, exact quarter boundaries, the
 * uncategorized bucket, an empty quarter, a case with no activity this
 * quarter, and the CSV serialisation shape.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Iv3ReportService;
use OCA\Procest\Service\Iv3TaakveldList;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService fake for Iv3ReportService: plain
 * searchObjectsBySlug(register, schema, filters) over a schema-keyed store.
 */
class Iv3FakeObjectService
{
    /**
     * Stored objects keyed by schema.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $store = [];

    /**
     * Search objects by slug — returns every row for the schema, ignoring
     * filters (Iv3ReportService loads everything and filters in PHP).
     *
     * @param string               $register The register slug.
     * @param string               $schema   The schema slug.
     * @param array<string, mixed> $filters  Ignored.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchObjectsBySlug(string $register, string $schema, array $filters=[]): array
    {
        return ($this->store[$schema] ?? []);
    }//end searchObjectsBySlug()
}//end class

/**
 * Unit tests for Iv3ReportService.
 *
 * @covers \OCA\Procest\Service\Iv3ReportService
 */
class Iv3ReportServiceTest extends TestCase
{
    /**
     * The in-memory object store fake.
     *
     * @var Iv3FakeObjectService
     */
    private Iv3FakeObjectService $objects;

    /**
     * Mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The service under test.
     *
     * @var Iv3ReportService
     */
    private Iv3ReportService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new Iv3FakeObjectService();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->settingsService->method('getObjectService')->willReturn($this->objects);
        $this->settingsService->method('getConfigValue')->willReturnCallback(
            function (string $key, string $default=''): string {
                return match ($key) {
                    'register' => 'procest',
                    'case_schema' => 'case',
                    'case_type_schema' => 'caseType',
                    default => $default,
                };
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new Iv3ReportService(
            settingsService: $this->settingsService,
            taakveldList: new Iv3TaakveldList(),
            logger: $logger,
        );
    }//end setUp()

    /**
     * Seed a caseType object.
     *
     * @param string      $id       Object id.
     * @param string|null $taakveld The iv3Taakveld value (null = unset).
     *
     * @return void
     */
    private function seedCaseType(string $id, ?string $taakveld): void
    {
        $row = ['id' => $id];
        if ($taakveld !== null) {
            $row['iv3Taakveld'] = $taakveld;
        }

        $this->objects->store['caseType'][] = $row;
    }//end seedCaseType()

    /**
     * Seed a case object.
     *
     * @param string                        $id         Object id.
     * @param string                        $caseTypeId Referenced caseType id.
     * @param array<int, array<string, mixed>> $kosten     Cost entries.
     *
     * @return void
     */
    private function seedCase(string $id, string $caseTypeId, array $kosten): void
    {
        $this->objects->store['case'][] = [
            'id'       => $id,
            'caseType' => $caseTypeId,
            'kosten'   => json_encode($kosten),
        ];
    }//end seedCase()

    /**
     * A single case with one taakveld aggregates correctly.
     *
     * @return void
     */
    public function testSingleCaseSingleTaakveldAggregatesCorrectly(): void
    {
        $this->seedCaseType('ct-1', '8.1');
        $this->seedCase(
            'case-1',
            'ct-1',
            [
                ['bedrag' => 100, 'type' => 'leges_income', 'datum' => '2026-04-05'],
                ['bedrag' => 60, 'type' => 'handling_cost', 'datum' => '2026-04-06'],
            ]
        );

        $report = $this->service->generateQuarterlyReport(2026, 2);

        $this->assertArrayHasKey('8.1', $report['perTaakveld']);
        $bucket = $report['perTaakveld']['8.1'];
        $this->assertSame(1, $bucket['caseCount']);
        $this->assertSame(60.0, $bucket['totalCosts']);
        $this->assertSame(100.0, $bucket['totalLegesIncome']);
        $this->assertSame(60.0, $bucket['avgCostPerCase']);
        $this->assertSame('Ruimtelijke ordening', $bucket['taakveldLabel']);
    }//end testSingleCaseSingleTaakveldAggregatesCorrectly()

    /**
     * Multiple cases across multiple taakvelden are kept in separate buckets.
     *
     * @return void
     */
    public function testMultipleTaakveldenAreKeptSeparate(): void
    {
        $this->seedCaseType('ct-a', '8.1');
        $this->seedCaseType('ct-b', '7.4');
        $this->seedCase('case-a', 'ct-a', [['bedrag' => 50, 'type' => 'handling_cost', 'datum' => '2026-05-01']]);
        $this->seedCase('case-b', 'ct-b', [['bedrag' => 200, 'type' => 'handling_cost', 'datum' => '2026-05-02']]);

        $report = $this->service->generateQuarterlyReport(2026, 2);

        $this->assertSame(50.0, $report['perTaakveld']['8.1']['totalCosts']);
        $this->assertSame(200.0, $report['perTaakveld']['7.4']['totalCosts']);
        $this->assertSame(1, $report['perTaakveld']['8.1']['caseCount']);
        $this->assertSame(1, $report['perTaakveld']['7.4']['caseCount']);
    }//end testMultipleTaakveldenAreKeptSeparate()

    /**
     * Quarter boundaries are exact — the last day of Q2 and the first day of
     * Q3 land in their own respective quarters only.
     *
     * @return void
     */
    public function testQuarterBoundariesAreExact(): void
    {
        $this->seedCaseType('ct-1', '1.1');
        $this->seedCase(
            'case-1',
            'ct-1',
            [
                ['bedrag' => 10, 'type' => 'handling_cost', 'datum' => '2026-06-30'],
                ['bedrag' => 20, 'type' => 'handling_cost', 'datum' => '2026-07-01'],
            ]
        );

        $q2 = $this->service->generateQuarterlyReport(2026, 2);
        $q3 = $this->service->generateQuarterlyReport(2026, 3);

        $this->assertSame(10.0, $q2['perTaakveld']['1.1']['totalCosts']);
        $this->assertSame(20.0, $q3['perTaakveld']['1.1']['totalCosts']);
    }//end testQuarterBoundariesAreExact()

    /**
     * A case whose case type has no taakveld is excluded from taakveld
     * buckets and reported separately as uncategorized.
     *
     * @return void
     */
    public function testCasesWithoutTaakveldAreUncategorized(): void
    {
        $this->seedCaseType('ct-1', null);
        $this->seedCase('case-1', 'ct-1', [['bedrag' => 75, 'type' => 'handling_cost', 'datum' => '2026-08-15']]);

        $report = $this->service->generateQuarterlyReport(2026, 3);

        $this->assertSame([], $report['perTaakveld']);
        $this->assertNotNull($report['uncategorized']);
        $this->assertSame(75.0, $report['uncategorized']['totalCosts']);
        $this->assertSame(1, $report['uncategorized']['caseCount']);
    }//end testCasesWithoutTaakveldAreUncategorized()

    /**
     * A quarter with no qualifying cost activity anywhere returns an empty
     * report shape rather than an error.
     *
     * @return void
     */
    public function testEmptyQuarterReturnsEmptyReport(): void
    {
        $this->seedCaseType('ct-1', '8.1');
        $this->seedCase('case-1', 'ct-1', [['bedrag' => 60, 'type' => 'handling_cost', 'datum' => '2026-01-15']]);

        $report = $this->service->generateQuarterlyReport(2026, 2);

        $this->assertSame([], $report['perTaakveld']);
        $this->assertNull($report['uncategorized']);
    }//end testEmptyQuarterReturnsEmptyReport()

    /**
     * A case with cost activity in a different quarter does not inflate the
     * requested quarter's case count.
     *
     * @return void
     */
    public function testCaseWithNoActivityThisQuarterIsExcluded(): void
    {
        $this->seedCaseType('ct-1', '3.1');
        $this->seedCase('case-1', 'ct-1', [['bedrag' => 40, 'type' => 'handling_cost', 'datum' => '2026-02-10']]);

        $report = $this->service->generateQuarterlyReport(2026, 2);

        $this->assertArrayNotHasKey('3.1', $report['perTaakveld']);
    }//end testCaseWithNoActivityThisQuarterIsExcluded()

    /**
     * The CSV header + one row per taakveld.
     *
     * @return void
     */
    public function testCsvContainsHeaderAndOneRowPerTaakveld(): void
    {
        $this->seedCaseType('ct-a', '8.1');
        $this->seedCaseType('ct-b', '7.4');
        $this->seedCase('case-a', 'ct-a', [['bedrag' => 50, 'type' => 'handling_cost', 'datum' => '2026-05-01']]);
        $this->seedCase('case-b', 'ct-b', [['bedrag' => 200, 'type' => 'handling_cost', 'datum' => '2026-05-02']]);

        $report = $this->service->generateQuarterlyReport(2026, 2);
        $csv    = $this->service->asCsv($report);
        $lines  = array_values(array_filter(explode("\n", $csv)));

        $this->assertSame('taakveld,label,caseCount,totalCosts,totalLegesIncome,avgCostPerCase', $lines[0]);
        $this->assertCount(3, $lines);
    }//end testCsvContainsHeaderAndOneRowPerTaakveld()

    /**
     * The CSV includes an uncategorized row when present.
     *
     * @return void
     */
    public function testCsvIncludesUncategorizedRowWhenPresent(): void
    {
        $this->seedCaseType('ct-1', null);
        $this->seedCase('case-1', 'ct-1', [['bedrag' => 75, 'type' => 'handling_cost', 'datum' => '2026-08-15']]);

        $report = $this->service->generateQuarterlyReport(2026, 3);
        $csv    = $this->service->asCsv($report);

        $this->assertStringContainsString('Uncategorized', $csv);
    }//end testCsvIncludesUncategorizedRowWhenPresent()
}//end class
