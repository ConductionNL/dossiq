<?php

/**
 * Unit tests for {@see \OCA\Procest\Service\ArchivalBatchService}.
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

use OCA\Procest\Service\ArchivalBatchService;
use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionAdapterInterface;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionResult;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ArchivalBatchService
 */
class ArchivalBatchServiceTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                          => 'procest',
                    'sip_bundel_schema'                 => 'sipBundel',
                    'overdracht_trigger_schema'         => 'overdrachtTrigger',
                    'overdracht_audit_log_schema'       => 'overdrachtAuditLog',
                    'overdracht_transactie_schema'      => 'overdrachtTransactie',
                    'archief_bewijs_schema'             => 'archiefBewijs',
                    default                              => '',
                };
            },
        );
        $this->settings = $settings;
    }

    /**
     * Two staged cases run through the batch processor → DEFERRED outcome
     * from the dormant adapter buckets them as `deferred`, batch terminates
     * in `completed` (zero failures), and the audit log carries both
     * `batch-initiated` and `batch-completed` rows.
     *
     * @return void
     */
    public function testBatchPathRunsCasesAndLogsLifecycle(): void
    {
        $this->objects->saveObject('procest', 'sipBundel', ['id' => 'sip-A', 'zaakId' => 'C/A']);
        $this->objects->saveObject('procest', 'sipBundel', ['id' => 'sip-B', 'zaakId' => 'C/B']);

        $adapter = $this->createMock(EDepotSubmissionAdapterInterface::class);
        $adapter->method('submit')->willReturn(new EDepotSubmissionResult(
            submissionStatus: 'DEFERRED',
            sipBundelId: 'sip-A',
            archiefId: '',
            overdrachtTransactieId: 'syn-A',
            dormant: true,
        ));
        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $adapter,
        );

        $svc     = new ArchivalBatchService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $summary = $svc->initiateBatch(['C/A', 'C/B'], 4, 'edepot-test', 'batch-test-1');

        self::assertSame('batch-test-1', $summary['batchId']);
        self::assertSame('completed', $summary['state']);
        self::assertSame(2, $summary['scheduled']);
        self::assertSame(2, $summary['deferred']);
        self::assertSame(0, $summary['failed']);

        $events = array_column(array_values($this->objects->store['overdrachtAuditLog'] ?? []), 'eventType');
        self::assertContains('batch-initiated', $events);
        self::assertContains('batch-completed', $events);
    }//end testBatchPathRunsCasesAndLogsLifecycle()

    /**
     * Missing SipBundel for a case → outcome is `deferred` (not `failed`)
     * because the daily detection sweep owns the recovery path.
     *
     * @return void
     */
    public function testBatchDefersWhenNoSipBundel(): void
    {
        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $this->createMock(EDepotSubmissionAdapterInterface::class),
        );
        $svc      = new ArchivalBatchService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $outcome  = $svc->processCaseInBatch('C/UNKNOWN');
        self::assertSame('deferred', $outcome);
    }//end testBatchDefersWhenNoSipBundel()

    /**
     * generateInspectionExport returns a JSON payload keyed by year +
     * filters with the per-trigger rows for that calendar year only.
     *
     * @return void
     */
    public function testInspectionExportSlicesByYear(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-1', 'zaakId' => 'C/1', 'zaaktypeKey' => 'omg',
            'afsluitingsDatum' => '2026-03-12', 'overdrachtDatum' => '2031-03-12',
            'status' => 'gereed-voor-overdracht',
        ]);
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-2', 'zaakId' => 'C/2', 'zaaktypeKey' => 'omg',
            'afsluitingsDatum' => '2025-09-01', 'overdrachtDatum' => '2030-09-01',
            'status' => 'gereed-voor-overdracht',
        ]);

        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $this->createMock(EDepotSubmissionAdapterInterface::class),
        );
        $svc     = new ArchivalBatchService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $payload = $svc->generateInspectionExport(2026, []);

        self::assertSame(2026, $payload['year']);
        self::assertSame(1, $payload['totals']['triggers']);
        self::assertCount(1, $payload['rows']);
        self::assertSame('C/1', $payload['rows'][0]['zaakId']);
    }//end testInspectionExportSlicesByYear()
}//end class
