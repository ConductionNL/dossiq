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
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnDailyScanService;
use OCA\Procest\Service\TermijnEscalationService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TermijnDailyScanService
 * @covers \OCA\Procest\Service\TermijnEscalationService
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
}
