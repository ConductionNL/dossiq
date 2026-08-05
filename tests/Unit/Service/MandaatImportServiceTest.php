<?php

/**
 * Unit tests for MandaatImportService.
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
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Mandaat\MandaatCsvParser;
use OCA\Procest\Service\Mandaat\MandaatRepository;
use OCA\Procest\Service\MandaatImportService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\MandaatImportService
 * @covers \OCA\Procest\Service\Mandaat\MandaatRepository
 * @covers \OCA\Procest\Service\Mandaat\MandaatCsvParser
 */
class MandaatImportServiceTest extends TestCase
{
    private FakeTermijnStore $objects;
    private MandaatImportService $service;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                       => 'procest',
                    'mandaterings_besluit_schema'    => 'mandateringsBesluit',
                    'mandaat_schema'                 => 'mandaat',
                    'organisatie_rol_schema'         => 'organisatieRol',
                    default                          => '',
                };
            },
        );
        // REAL repository + parser over the same fake object store, so these
        // tests still exercise the production register access and CSV parsing.
        $this->service = new MandaatImportService(
            repository: new MandaatRepository($settings, $this->createMock(LoggerInterface::class)),
            csvParser: new MandaatCsvParser(),
        );

        // Seed roles.
        $this->objects->saveObject('procest', 'organisatieRol', ['id' => 'rol-consulent', 'rolNaam' => 'Consulent']);
        $this->objects->saveObject('procest', 'organisatieRol', ['id' => 'rol-manager', 'rolNaam' => 'Afdelingsmanager']);
    }

    /**
     * @return void
     */
    public function testImportCreatesBesluitAndMandaten(): void
    {
        $csv = "mandaatNummer,omschrijving,rolNaam,plafondCents,subdelegatie,wettelijkeGrondslag,decisionTypes\n"
            ."WMO-001,WMO toekenning beperkt,Consulent,500000,false,Wmo art 2.3,wmo-toekenning\n"
            ."WMO-002,WMO toekenning ruim,Afdelingsmanager,2500000,true,Wmo art 2.3,wmo-toekenning;wmo-afwijzing\n";

        $r = $this->service->importFromCsv('B-2026-1', 'Mandaatregeling WMO 2026', 'decidesk-uuid-1', $csv);
        self::assertSame(2, $r['totalMandaten']);
        self::assertSame(2, $r['newCount']);
        self::assertSame(0, $r['changedCount']);
        self::assertSame(0, $r['removedCount']);

        $besluit = $this->objects->store['mandateringsBesluit'][$r['mandateringsBesluitId']];
        self::assertSame('concept', $besluit['status']);
        self::assertCount(2, $this->objects->store['mandaat']);

        $m = array_values($this->objects->store['mandaat'])[0];
        self::assertSame(500000, $m['voorwaarden']['plafondCents']);
        self::assertFalse($m['voorwaarden']['subdelegatie']);
        self::assertSame(['wmo-toekenning'], $m['voorwaarden']['decisionTypes']);
    }

    /**
     * @return void
     */
    public function testImportFailsOnUnknownRole(): void
    {
        $csv = "mandaatNummer,omschrijving,rolNaam,plafondCents\nX-1,Test,Bestuurder,1000\n";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bestuurder');
        $this->service->importFromCsv('B-2026-2', 'Test', 'decidesk-uuid-2', $csv);
    }

    /**
     * @return void
     */
    public function testImportFailsOnMissingRequiredColumn(): void
    {
        $csv = "mandaatNummer,omschrijving,rolNaam\nWMO-001,Test,Consulent\n";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plafondCents');
        $this->service->importFromCsv('B-2026-3', 'Test', 'decidesk-uuid-3', $csv);
    }

    /**
     * @return void
     */
    public function testApproveFlipsBesluitAndMandatenActive(): void
    {
        $csv = "mandaatNummer,omschrijving,rolNaam,plafondCents\nWMO-001,X,Consulent,500000\n";
        $r = $this->service->importFromCsv('B-2026-4', 'X', 'decidesk-uuid-4', $csv);
        $approved = $this->service->approveImport((string) $r['mandateringsBesluitId']);

        self::assertSame('vastgesteld', $approved['status']);
        $m = array_values($this->objects->store['mandaat'])[0];
        self::assertSame('active', $m['status']);
    }

    /**
     * @return void
     */
    public function testReimportDetectsChangedFields(): void
    {
        // First import + approve.
        $csv1 = "mandaatNummer,omschrijving,rolNaam,plafondCents\nWMO-001,Original,Consulent,500000\n";
        $r1 = $this->service->importFromCsv('B-2026-5', 'V1', 'decidesk-uuid-5', $csv1);
        $this->service->approveImport((string) $r1['mandateringsBesluitId']);

        // Re-import with different plafond + a removed row.
        $csv2 = "mandaatNummer,omschrijving,rolNaam,plafondCents\nWMO-001,Updated,Consulent,1000000\n";
        $r2 = $this->service->importFromCsv('B-2026-5', 'V2', 'decidesk-uuid-5', $csv2);

        self::assertSame(0, $r2['newCount']);
        self::assertSame(1, $r2['changedCount']);
        self::assertSame(0, $r2['removedCount']);
        $diff = $r2['diff'][0];
        self::assertSame('CHANGED', $diff['change']);
        self::assertContains('omschrijving', $diff['fields']);
        self::assertContains('plafondCents', $diff['fields']);
    }
}
