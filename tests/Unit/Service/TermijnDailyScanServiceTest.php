<?php

/**
 * Unit tests for TermijnDailyScanService + TermijnEscalationService.
 *
 * Drives the daily sweep through bucketing, overdue flips, pause-expiry,
 * and duplicate-suppression scenarios against an in-memory store.
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

use DateTimeImmutable;
use OCA\Procest\Service\DwangsomCalculationService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnDailyScanService;
use OCA\Procest\Service\TermijnEscalationService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TermijnDailyScanService
 * @covers \OCA\Procest\Service\TermijnEscalationService
 *
 * @uses \OCA\Procest\Service\DwangsomCalculationService
 * @uses \OCA\Procest\Service\TermijnService
 */
class TermijnDailyScanServiceTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;
    private TermijnService $termijnService;
    private TermijnEscalationService $escalation;
    private TermijnDailyScanService $scan;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_definitie_schema'   => 'termijnDefinitie',
                    'termijn_instance_schema'    => 'termijnInstance',
                    'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
                    default                      => '',
                };
            },
        );

        $this->settings        = $settings;
        $logger                = $this->createMock(LoggerInterface::class);
        $this->termijnService  = new TermijnService($settings, $logger);
        $this->escalation      = new TermijnEscalationService($this->termijnService, $logger);
        $this->scan            = new TermijnDailyScanService(
            $settings,
            $this->termijnService,
            $this->escalation,
            $logger
        );
    }

    /**
     * @param string $deadline Deadline (YYYY-MM-DD).
     * @param string $status   Status.
     * @return array<string, mixed>
     */
    private function seedInstance(string $deadline, string $status = 'lopend'): array
    {
        return $this->objects->saveObject('procest', 'termijnInstance', [
            'zaak'                  => 'Z/2026/X',
            'termijnDefinitie'      => 'td-ov',
            'startDatum'            => '2026-01-01T10:00:00+00:00',
            'einddatumBerekend'     => $deadline,
            'einddatumActueel'      => $deadline,
            'status'                => $status,
            'notificatiesVerstuurd' => [],
        ]);
    }

    /**
     * @return void
     */
    public function testBucketingAt14d(): void
    {
        self::assertSame(14, $this->escalation->bucketFor(14));
        self::assertSame(14, $this->escalation->bucketFor(10));
        self::assertSame(7, $this->escalation->bucketFor(7));
        self::assertSame(7, $this->escalation->bucketFor(3));
        self::assertSame(2, $this->escalation->bucketFor(2));
        self::assertSame(2, $this->escalation->bucketFor(1));
        self::assertSame(0, $this->escalation->bucketFor(0));
        self::assertSame(0, $this->escalation->bucketFor(-5));
        self::assertNull($this->escalation->bucketFor(30));
    }

    /**
     * @return void
     */
    public function testDuplicateSuppressionPerThreshold(): void
    {
        $instance = $this->seedInstance('2026-06-15');
        $instance['id'] = (string) $instance['id'];

        $sent1 = $this->escalation->notifyThreshold($instance, 14);
        self::assertTrue($sent1);

        $reloaded = $this->termijnService->getTermijnInstance((string) $instance['id']);
        $sent2    = $this->escalation->notifyThreshold($reloaded, 14);
        self::assertFalse($sent2);
    }

    /**
     * @return void
     */
    public function testScanFlipsOverdueInstanceToOverschreden(): void
    {
        $this->seedInstance('2026-05-01');
        $counts = $this->scan->run(new DateTimeImmutable('2026-06-01T10:00:00+00:00'));

        self::assertSame(1, $counts['scanned']);
        self::assertSame(1, $counts['overschreden']);
        self::assertSame(1, $counts['escalated']);

        $rows = array_values($this->objects->store['termijnInstance']);
        self::assertSame('overschreden', $rows[0]['status']);

        $events    = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
        $overTypes = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'overschreden'));
        self::assertCount(1, $overTypes);
    }

    /**
     * @return void
     */
    public function testScanRaisesPauseExpiredEvent(): void
    {
        $row = $this->seedInstance('2026-07-01', 'gepauzeerd');
        $row['pauzeDeadline'] = '2026-05-30';
        $this->objects->store['termijnInstance'][$row['id']] = $row;

        $counts = $this->scan->run(new DateTimeImmutable('2026-06-01T10:00:00+00:00'));
        self::assertSame(1, $counts['pauseExpired']);

        $events = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
        $pauseExpired = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'pauze-verlopen'));
        self::assertCount(1, $pauseExpired);
    }

    /**
     * @return void
     */
    public function testScanIgnoresCompletedInstances(): void
    {
        $this->seedInstance('2026-05-01', 'voltooid');
        $counts = $this->scan->run(new DateTimeImmutable('2026-06-01T10:00:00+00:00'));
        self::assertSame(0, $counts['overschreden']);
        self::assertSame(0, $counts['escalated']);
    }

    /**
     * Dwangsom accrual sweep: every `lopend` dwangsomBerekening row is
     * advanced by exactly one day per `TermijnDailyScanService::run()`
     * pass. The scan does NOT recalculate retroactively — yesterday's
     * cumulatievBedrag is the floor and the current tier-day adds on top.
     * Closes the
     * `termijnbewaking-dwangsom-engine-06-dwangsom-calculation` integration
     * deferral for "scan accrues each lopend berekening".
     *
     * @return void
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-06-dwangsom-calculation/tasks.md
     */
    public function testScanAccruesEveryLopendDwangsomBerekening(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_definitie_schema'   => 'termijnDefinitie',
                    'termijn_instance_schema'    => 'termijnInstance',
                    'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
                    'dwangsom_berekening_schema' => 'dwangsomBerekening',
                    default                       => '',
                };
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $calc   = new DwangsomCalculationService($settings, $logger);
        $scan   = new TermijnDailyScanService(
            $settings,
            new TermijnService($settings, $logger),
            new TermijnEscalationService(new TermijnService($settings, $logger), $logger),
            $logger,
            $calc
        );

        // Three lopend rows on day 0 — tier-1 increment is €23 (2300 cents).
        foreach (['b1', 'b2', 'b3'] as $id) {
            $this->objects->saveObject('procest', 'dwangsomBerekening', [
                'id'                => $id,
                'termijnInstance'   => 'ti-'.$id,
                'huidigeDag'        => 0,
                'cumulatievBedrag'  => 0,
                'plafondBereikt'    => false,
                'status'            => 'lopend',
            ]);
        }
        // One stopped row must be skipped.
        $this->objects->saveObject('procest', 'dwangsomBerekening', [
            'id'                => 'b-stopped',
            'termijnInstance'   => 'ti-stopped',
            'huidigeDag'        => 99,
            'cumulatievBedrag'  => 999999,
            'plafondBereikt'    => false,
            'status'            => 'gestopt-wegens-beschikking',
        ]);

        $counts = $scan->run(new DateTimeImmutable('2026-06-01T10:00:00+00:00'));

        self::assertSame(3, $counts['dwangsomAccrued'], 'Scan must accrue every lopend row exactly once.');

        // Verify each lopend row advanced one tier-1 day; stopped row untouched.
        foreach (['b1', 'b2', 'b3'] as $id) {
            $row = $this->objects->store['dwangsomBerekening'][$id];
            self::assertSame(1, $row['huidigeDag'], $id.' must advance huidigeDag by 1.');
            self::assertSame(2300, $row['cumulatievBedrag'], $id.' must add tier-1 (2300 cents) once.');
        }
        $stopped = $this->objects->store['dwangsomBerekening']['b-stopped'];
        self::assertSame(99, $stopped['huidigeDag'], 'stopped row must not advance.');
        self::assertSame(999999, $stopped['cumulatievBedrag'], 'stopped row must not accrue.');
    }
}
