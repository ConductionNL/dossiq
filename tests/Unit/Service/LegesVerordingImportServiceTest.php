<?php

/**
 * LegesVerordingImportService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-001
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LegesVerordingImportService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for LegesVerordingImportService.
 *
 * @covers \OCA\Procest\Service\LegesVerordingImportService
 */
class LegesVerordingImportServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var LegesVerordingImportService
     */
    private LegesVerordingImportService $service;

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->service         = new LegesVerordingImportService(
            settingsService: $this->settingsService,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * CSV parsing keys cells by header column.
     *
     * @return void
     */
    public function testParseCsv(): void
    {
        $csv  = "tariefNummer,omschrijving,bedrag,grondslag,eenheid,btwTarief,grootboekrekening\n"
            ."1.1.1,Paspoort,10000,vast,per_stuk,0,8004010\n"
            ."3.2.1,APV evenement,25000,vast,per_stuk,9,8004060\n";
        $rows = $this->service->parseRawTable(bytes: $csv, format: 'csv');

        $this->assertCount(2, $rows);
        $this->assertSame('Paspoort', $rows[0]['omschrijving']);
        $this->assertSame('25000', $rows[1]['bedrag']);
    }//end testParseCsv()

    /**
     * CSV parsing supports a semicolon delimiter.
     *
     * @return void
     */
    public function testParseCsvSemicolon(): void
    {
        $csv  = "tariefNummer;omschrijving;bedrag;grondslag;eenheid;btwTarief;grootboekrekening\n"
            ."1.1.1;Paspoort;10000;vast;per_stuk;0;8004010\n";
        $rows = $this->service->parseRawTable(bytes: $csv, format: 'csv');

        $this->assertCount(1, $rows);
        $this->assertSame('1.1.1', $rows[0]['tariefNummer']);
    }//end testParseCsvSemicolon()

    /**
     * Validation accepts a well-formed row and rejects bad VAT/missing fields.
     *
     * @return void
     */
    public function testValidateTariffs(): void
    {
        $rows = [
            [
                'tariefNummer'      => '1.1.1',
                'omschrijving'      => 'Paspoort',
                'bedrag'            => '10000',
                'grondslag'         => 'vast',
                'eenheid'           => 'per_stuk',
                'btwTarief'         => '0',
                'grootboekrekening' => '8004010',
            ],
            [
                'tariefNummer'      => '2.2.2',
                'omschrijving'      => 'Slecht',
                'bedrag'            => 'NaN',
                'grondslag'         => 'onbekend',
                'eenheid'           => 'per_stuk',
                'btwTarief'         => '13',
                'grootboekrekening' => '',
            ],
        ];

        $result = $this->service->validateTariffs(rows: $rows);

        $this->assertCount(1, $result['valid']);
        $this->assertSame(10000, $result['valid'][0]['bedrag']);
        $this->assertNotEmpty($result['errors']);
    }//end testValidateTariffs()

    /**
     * Diff classifies new, changed and deleted tariffs.
     *
     * @return void
     */
    public function testDiff(): void
    {
        $existing = [
            ['tariefNummer' => '1.1.1', 'bedrag' => 9000],
            ['tariefNummer' => '9.9.9', 'bedrag' => 5000],
        ];
        $new      = [
            ['tariefNummer' => '1.1.1', 'bedrag' => 10000],
            ['tariefNummer' => '3.2.1', 'bedrag' => 25000],
        ];

        $diff = $this->service->diff(newRows: $new, existingRows: $existing);

        $this->assertCount(1, $diff['new']);
        $this->assertSame('3.2.1', $diff['new'][0]['tariefNummer']);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('1.1.1', $diff['changed'][0]['tariefNummer']);
        $this->assertCount(1, $diff['deleted']);
        $this->assertSame('9.9.9', $diff['deleted'][0]['tariefNummer']);
    }//end testDiff()

    /**
     * Import throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testCreateThrowsWhenNoObjectService(): void
    {
        $this->settingsService->method('getObjectService')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service->createTariefTabelVersion(
            metaData: ['naam' => 'Test', 'geldigVanaf' => '2026-01-01'],
            rows: []
        );
    }//end testCreateThrowsWhenNoObjectService()
}//end class
