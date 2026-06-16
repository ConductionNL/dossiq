<?php

/**
 * Unit tests for the RollbackManager (rollback + retry-after-correction).
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

use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionAdapterInterface;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionResult;
use OCA\Procest\Service\ProofOfTransferService;
use OCA\Procest\Service\RollbackManager;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\RollbackManager
 */
class RollbackManagerTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                     => 'procest',
                    'overdracht_trigger_schema'    => 'overdrachtTrigger',
                    'overdracht_transactie_schema' => 'overdrachtTransactie',
                    'overdracht_audit_log_schema'  => 'overdrachtAuditLog',
                    'sip_bundel_schema'            => 'sipBundel',
                    'archief_bewijs_schema'        => 'archiefBewijs',
                    default                        => '',
                };
            },
        );
        $this->settings = $settings;
        $this->logger   = $this->createMock(LoggerInterface::class);
    }

    /**
     * Build a RollbackManager with an e-Depot submitter that returns the
     * given submission status.
     *
     * @param string $submissionStatus Status the submitter reports.
     *
     * @return RollbackManager
     */
    private function manager(string $submissionStatus): RollbackManager
    {
        $adapter = $this->createMock(EDepotSubmissionAdapterInterface::class);
        $adapter->method('submit')->willReturn(new EDepotSubmissionResult(
            submissionStatus: $submissionStatus,
            sipBundelId: 'sip-retry',
            archiefId: ($submissionStatus !== 'FAILED' ? 'ARCH-RETRY-1' : ''),
            overdrachtTransactieId: 'tx-retry-1',
            dormant: true,
        ));
        $trigger = new ArchivalTriggerService($this->settings, $this->logger, null, $adapter);
        $proof   = new ProofOfTransferService($this->settings, $trigger, $this->logger);
        return new RollbackManager($this->settings, $trigger, $proof, $this->logger);
    }

    /**
     * onIngestionFailure marks transaction failed-final, flips trigger to
     * gefaald, preserves SIP + case, records corrective action + audit row.
     *
     * @return void
     */
    public function testOnIngestionFailureRollsBackCleanly(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTransactie', [
            'id' => 'tx-1', 'zaakId' => 'C/1', 'sipBundelId' => 'sip-1', 'status' => 'pending',
        ]);
        $this->objects->saveObject('procest', 'sipBundel', [
            'id' => 'sip-1', 'zaakId' => 'C/1', 'manifestChecksum' => 'abc',
        ]);
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-1', 'zaakId' => 'C/1', 'status' => 'in-overdracht',
        ]);

        $result = $this->manager('DEFERRED')->onIngestionFailure('tx-1', 'MDTO_VALIDATION_FAILED', 'field x missing');

        self::assertSame('gefaald', $result['status']);
        self::assertSame('tr-1', $result['triggerId']);
        self::assertStringContainsString('MDTO', $result['correctiveAction']);

        // Transaction is failed-final with the error stored.
        $tx = $this->objects->find('tx-1', 'procest', 'overdrachtTransactie');
        self::assertSame('failed-final', $tx['status']);
        self::assertSame('MDTO_VALIDATION_FAILED', $tx['errorCode']);
        self::assertSame('field x missing', $tx['errorResponse']);

        // Trigger flipped to gefaald.
        $tr = $this->objects->find('tr-1', 'procest', 'overdrachtTrigger');
        self::assertSame('gefaald', $tr['status']);

        // SIP is preserved (not deleted / not mutated to a destroyed state).
        $sip = $this->objects->find('sip-1', 'procest', 'sipBundel');
        self::assertNotNull($sip);
        self::assertSame('abc', $sip['manifestChecksum']);

        // submission-failed-rollback + div-task-created audit rows recorded.
        $events = array_column(array_values($this->objects->store['overdrachtAuditLog'] ?? []), 'eventType');
        self::assertContains('submission-failed-rollback', $events);
        self::assertContains('div-task-created', $events);
    }

    /**
     * Successful retry: gefaald trigger → re-submit → geslaagd + ArchiefBewijs,
     * both transactions retained in the audit log.
     *
     * @return void
     */
    public function testRetryAfterCorrectionSucceeds(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-2', 'zaakId' => 'C/2', 'status' => 'gefaald',
        ]);

        $result = $this->manager('SUBMISSION_DEFERRED')->retryAfterCorrection('tr-2');

        self::assertTrue($result['ok']);
        self::assertSame('geslaagd', $result['status']);
        self::assertSame('tx-retry-1', $result['newTransactionId']);

        // Trigger flipped to geslaagd.
        $tr = $this->objects->find('tr-2', 'procest', 'overdrachtTrigger');
        self::assertSame('geslaagd', $tr['status']);

        // ArchiefBewijs captured.
        self::assertArrayHasKey('archiefBewijs', $this->objects->store);
        self::assertNotEmpty($this->objects->store['archiefBewijs']);

        // retry-after-correction audit row retained (alongside submit events).
        $events = array_column(array_values($this->objects->store['overdrachtAuditLog'] ?? []), 'eventType');
        self::assertContains('retry-after-correction', $events);
    }

    /**
     * Failed retry: submitter reports FAILED → trigger stays recoverable
     * (gefaald), no proof captured; outcome recorded as not-ok.
     *
     * @return void
     */
    public function testRetryAfterCorrectionFailureRestoresGefaald(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-3', 'zaakId' => 'C/3', 'status' => 'gefaald',
        ]);

        $result = $this->manager('FAILED')->retryAfterCorrection('tr-3');

        self::assertFalse($result['ok']);
        self::assertSame('gefaald', $result['status']);

        // Trigger remains gefaald (recoverable), NOT geslaagd.
        $tr = $this->objects->find('tr-3', 'procest', 'overdrachtTrigger');
        self::assertSame('gefaald', $tr['status']);

        // No ArchiefBewijs captured on a failed retry.
        self::assertArrayNotHasKey('archiefBewijs', $this->objects->store);
    }

    /**
     * Retry rejects a trigger that is not in status gefaald (no submit).
     *
     * @return void
     */
    public function testRetryRejectsNonGefaaldTrigger(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-4', 'zaakId' => 'C/4', 'status' => 'geslaagd',
        ]);

        $result = $this->manager('DEFERRED')->retryAfterCorrection('tr-4');

        self::assertFalse($result['ok']);
        self::assertSame('geslaagd', $result['status']);
        self::assertStringContainsString('gefaald', $result['message']);
        self::assertSame('NONE', $result['submissionStatus']);
    }

    /**
     * Retry on an unknown trigger returns ok=false / unknown.
     *
     * @return void
     */
    public function testRetryUnknownTrigger(): void
    {
        $result = $this->manager('DEFERRED')->retryAfterCorrection('nope');
        self::assertFalse($result['ok']);
        self::assertSame('unknown', $result['status']);
    }
}//end class
