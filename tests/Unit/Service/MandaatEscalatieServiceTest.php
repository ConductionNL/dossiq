<?php

/**
 * Unit tests for MandaatEscalatieService.
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

use OCA\Procest\Service\MandaatEscalatieService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\MandaatEscalatieService
 */
class MandaatEscalatieServiceTest extends TestCase
{
    private FakeTermijnStore $objects;
    private MandaatEscalatieService $service;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                            => 'procest',
                    'mandaat_schema'                       => 'mandaat',
                    'medewerker_rol_toewijzing_schema'     => 'medewerkerRolToewijzing',
                    'mandaat_escalatie_schema'             => 'mandaatEscalatie',
                    default                                => '',
                };
            },
        );
        $this->service = new MandaatEscalatieService($settings, $this->createMock(LoggerInterface::class));

        // Seed mandates + assignments.
        $this->objects->saveObject('procest', 'mandaat', [
            'id' => 'm-low',
            'gemandateerdeRol' => 'rol-consulent',
            'voorwaarden' => ['plafondCents' => 500000, 'decisionTypes' => ['wmo-toekenning']],
            'status' => 'active',
        ]);
        $this->objects->saveObject('procest', 'mandaat', [
            'id' => 'm-high',
            'gemandateerdeRol' => 'rol-manager',
            'voorwaarden' => ['plafondCents' => 2500000, 'decisionTypes' => ['wmo-toekenning']],
            'status' => 'active',
        ]);
        $this->objects->saveObject('procest', 'medewerkerRolToewijzing', [
            'userId' => 'carol', 'rolId' => 'rol-manager', 'toewijzingType' => 'primair', 'validFrom' => '2026-01-01',
        ]);
    }

    /**
     * @return void
     */
    public function testCreateEscalatieResolvesNextHigherHolder(): void
    {
        $row = $this->service->createEscalatie('Z/2026/E1', 'wmo-toekenning', 'alice', 'plafond_overschreden');
        self::assertSame('open', $row['status']);
        self::assertSame('carol', $row['targetUserId']);
        self::assertSame('m-high', $row['targetMandaatId']);
    }

    /**
     * @return void
     */
    public function testApproveByCorrectMandateHolder(): void
    {
        $created = $this->service->createEscalatie('Z/2026/E2', 'wmo-toekenning', 'alice', 'niet_bevoegd');
        $approved = $this->service->approveEscalatie((string) $created['id'], 'carol');
        self::assertSame('goedgekeurd', $approved['status']);
    }

    /**
     * @return void
     */
    public function testApproveByWrongUserRejects(): void
    {
        $created = $this->service->createEscalatie('Z/2026/E3', 'wmo-toekenning', 'alice', 'niet_bevoegd');
        $this->expectException(RuntimeException::class);
        $this->service->approveEscalatie((string) $created['id'], 'bob');
    }

    /**
     * @return void
     */
    public function testRejectEscalatieRecordsReason(): void
    {
        $created = $this->service->createEscalatie('Z/2026/E4', 'wmo-toekenning', 'alice', 'niet_bevoegd');
        $rejected = $this->service->rejectEscalatie((string) $created['id'], 'Onvoldoende onderbouwing');
        self::assertSame('afgewezen', $rejected['status']);
        self::assertSame('Onvoldoende onderbouwing', $rejected['afgewezenReden']);
    }

    /**
     * @return void
     */
    public function testAutoRerouteOnPersonnelChange(): void
    {
        $this->service->createEscalatie('Z/2026/E5', 'wmo-toekenning', 'alice', 'niet_bevoegd');
        $this->service->createEscalatie('Z/2026/E6', 'wmo-toekenning', 'alice', 'plafond_overschreden');

        $count = $this->service->autoRerouteOnPersonnelChange('carol', 'dave');
        self::assertSame(2, $count);

        foreach ($this->objects->store['mandaatEscalatie'] as $row) {
            self::assertSame('dave', $row['targetUserId']);
        }
    }
}
