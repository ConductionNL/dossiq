<?php

/**
 * Unit tests for {@see \OCA\Procest\Service\ArchivalSubmissionRetryService}.
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

use OCA\Procest\Service\ArchivalSubmissionRetryService;
use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionAdapterInterface;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionResult;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\ArchivalSubmissionRetryService
 */
class ArchivalSubmissionRetryServiceTest extends TestCase
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
                    'overdracht_transactie_schema'      => 'overdrachtTransactie',
                    'overdracht_audit_log_schema'       => 'overdrachtAuditLog',
                    default                              => '',
                };
            },
        );
        $this->settings = $settings;
    }

    /**
     * Sweep retries a failed transaction whose backoff window has elapsed,
     * writes a NEW transactie row with attemptNumber=2, and leaves the
     * fresh row in `pending` when the adapter reports DEFERRED.
     *
     * @return void
     */
    public function testRetryAdvancesAttemptNumberAndDeferralStays(): void
    {
        // Seed: one failed transaction, attempt 1, 5 minutes ago.
        $oldTs = (new \DateTimeImmutable('@'.(time() - 600)))->format('Y-m-d\TH:i:sP');
        $this->objects->saveObject('procest', 'overdrachtTransactie', [
            'id'            => 'ot-1',
            'sipBundelId'   => 'sip-1',
            'zaakId'        => 'C/9',
            'attemptNumber' => 1,
            'status'        => 'failed',
            'timestamp'     => $oldTs,
        ]);

        $adapter = $this->createMock(EDepotSubmissionAdapterInterface::class);
        $adapter->method('submit')->willReturn(new EDepotSubmissionResult(
            submissionStatus: 'DEFERRED',
            sipBundelId: 'sip-1',
            archiefId: '',
            overdrachtTransactieId: 'syn-1',
            dormant: true,
        ));
        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $adapter,
        );

        $svc    = new ArchivalSubmissionRetryService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $counts = $svc->processRetryQueue();

        self::assertSame(1, $counts['retried']);
        self::assertSame(0, $counts['skipped_backoff']);
        self::assertSame(0, $counts['escalated']);

        $rows  = array_values($this->objects->store['overdrachtTransactie']);
        $newer = array_values(array_filter($rows, static fn (array $r): bool => ($r['attemptNumber'] ?? 0) === 2));
        self::assertCount(1, $newer);
        self::assertSame('pending', $newer[0]['status']);
        self::assertSame('sip-1', $newer[0]['sipBundelId']);
    }//end testRetryAdvancesAttemptNumberAndDeferralStays()

    /**
     * Sweep skips a failed transaction whose last timestamp is INSIDE the
     * backoff window (attempt 1 = 60s wait, last try 10s ago → skip).
     *
     * @return void
     */
    public function testRetrySkipsInsideBackoffWindow(): void
    {
        $recentTs = (new \DateTimeImmutable('@'.(time() - 10)))->format('Y-m-d\TH:i:sP');
        $this->objects->saveObject('procest', 'overdrachtTransactie', [
            'id'            => 'ot-2',
            'sipBundelId'   => 'sip-2',
            'zaakId'        => 'C/10',
            'attemptNumber' => 1,
            'status'        => 'failed',
            'timestamp'     => $recentTs,
        ]);

        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $this->createMock(EDepotSubmissionAdapterInterface::class),
        );
        $svc    = new ArchivalSubmissionRetryService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $counts = $svc->processRetryQueue();

        self::assertSame(1, $counts['skipped_backoff']);
        self::assertSame(0, $counts['retried']);
    }//end testRetrySkipsInsideBackoffWindow()

    /**
     * Sweep escalates a transaction at the 5-attempt threshold instead of
     * dispatching a 6th try — that row is left in place; an audit-log
     * entry is appended.
     *
     * @return void
     */
    public function testRetryEscalatesAtThreshold(): void
    {
        $oldTs = (new \DateTimeImmutable('@'.(time() - 100000)))->format('Y-m-d\TH:i:sP');
        $this->objects->saveObject('procest', 'overdrachtTransactie', [
            'id'            => 'ot-5',
            'sipBundelId'   => 'sip-5',
            'zaakId'        => 'C/11',
            'attemptNumber' => 5,
            'status'        => 'failed',
            'timestamp'     => $oldTs,
        ]);

        $triggerSvc = new ArchivalTriggerService(
            $this->settings,
            $this->createMock(LoggerInterface::class),
            null,
            $this->createMock(EDepotSubmissionAdapterInterface::class),
        );
        $svc    = new ArchivalSubmissionRetryService($this->settings, $triggerSvc, $this->createMock(LoggerInterface::class));
        $counts = $svc->processRetryQueue();

        self::assertSame(1, $counts['escalated']);
        self::assertSame(0, $counts['retried']);

        // Audit log row should carry submission-escalated.
        $audits = array_values($this->objects->store['overdrachtAuditLog'] ?? []);
        $types  = array_column($audits, 'eventType');
        self::assertContains('submission-escalated', $types);
    }//end testRetryEscalatesAtThreshold()
}//end class
