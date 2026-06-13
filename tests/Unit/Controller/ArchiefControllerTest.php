<?php

/**
 * Unit tests for the archief-edepot REST surface.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
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

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ArchiefController;
use OCA\Procest\Service\ArchivalBatchService;
use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionAdapterInterface;
use OCA\Procest\Service\External\Tmlo\EDepotSubmissionResult;
use OCA\Procest\Service\ProofOfTransferService;
use OCA\Procest\Service\RollbackManager;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Tests\Unit\Service\FakeTermijnStore;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exercises the four batch + inspection endpoints added in W15.
 *
 * Each test wires the controller against the in-memory `FakeTermijnStore`
 * via a mock `SettingsService` and the real `ArchivalBatchService`. The
 * dormant `EDepotSubmissionAdapterInterface` mock keeps every dispatch in
 * the `DEFERRED` bucket so the audit-log + counter assertions are stable.
 *
 * @covers \OCA\Procest\Controller\ArchiefController
 */
class ArchiefControllerTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;
    private ArchivalTriggerService $triggerSvc;
    private ArchivalBatchService $batchSvc;
    private RollbackManager $rollbackMgr;
    private IGroupManager $groupManager;
    private IRequest $request;
    private IUserSession $session;
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
                    'sip_bundel_schema'            => 'sipBundel',
                    'overdracht_trigger_schema'    => 'overdrachtTrigger',
                    'overdracht_audit_log_schema'  => 'overdrachtAuditLog',
                    'overdracht_transactie_schema' => 'overdrachtTransactie',
                    'archief_bewijs_schema'        => 'archiefBewijs',
                    default                        => '',
                };
            },
        );
        $this->settings = $settings;

        $adapter = $this->createMock(EDepotSubmissionAdapterInterface::class);
        $adapter->method('submit')->willReturn(new EDepotSubmissionResult(
            submissionStatus: 'DEFERRED',
            sipBundelId: '',
            archiefId: '',
            overdrachtTransactieId: 'syn-1',
            dormant: true,
        ));

        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->triggerSvc = new ArchivalTriggerService($this->settings, $this->logger, null, $adapter);
        $this->batchSvc   = new ArchivalBatchService($this->settings, $this->triggerSvc, $this->logger);
        $proofSvc         = new ProofOfTransferService($this->settings, $this->triggerSvc, $this->logger);
        $this->rollbackMgr = new RollbackManager($this->settings, $this->triggerSvc, $proofSvc, $this->logger);

        $this->request = $this->createMock(IRequest::class);
        $user          = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->session = $this->createMock(IUserSession::class);
        $this->session->method('getUser')->willReturn($user);

        // Default: caller IS an authorised archief role (admin).
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->groupManager->method('isInGroup')->willReturn(true);
    }

    private function controller(): ArchiefController
    {
        return new ArchiefController(
            'procest',
            $this->request,
            $this->settings,
            $this->session,
            $this->logger,
            $this->batchSvc,
            $this->rollbackMgr,
            $this->groupManager,
        );
    }

    /**
     * Empty caseIds → 400.
     *
     * @return void
     */
    public function testBatchInitiateRejectsEmptyCaseIds(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'archief');
        file_put_contents($tmp, '{}');
        // batchInitiate reads php://input; PHPUnit can't fake that stream,
        // so emulate by stubbing the jsonBody path via empty payload behaviour.
        // Use the public API instead: empty body decodes to [], triggering 400.
        $response = $this->controller()->batchInitiate();
        unlink($tmp);
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Batch end-to-end: stage two SIPs, initiate → dormant adapter buckets
     * both as `deferred`, status reports `completed`, report wraps counters
     * and bewijzen rows.
     *
     * @return void
     */
    public function testBatchInitiateStatusAndReportRoundTrip(): void
    {
        $this->objects->saveObject('procest', 'sipBundel', ['id' => 'sip-A', 'zaakId' => 'C/A']);
        $this->objects->saveObject('procest', 'sipBundel', ['id' => 'sip-B', 'zaakId' => 'C/B']);
        $this->objects->saveObject('procest', 'archiefBewijs', ['id' => 'b-A', 'zaakId' => 'C/A', 'hash' => 'aaaa']);
        $this->objects->saveObject('procest', 'archiefBewijs', ['id' => 'b-C', 'zaakId' => 'C/C', 'hash' => 'cccc']);

        // Drive initiateBatch directly (bypasses php://input plumbing).
        $summary = $this->batchSvc->initiateBatch(['C/A', 'C/B'], 4, 'edepot-test', 'batch-rt-1');
        self::assertSame('completed', $summary['state']);
        self::assertSame(2, $summary['deferred']);

        // Status endpoint replays the audit log.
        $statusResp = $this->controller()->batchStatus('batch-rt-1');
        self::assertSame(Http::STATUS_OK, $statusResp->getStatus());
        $statusBody = $statusResp->getData();
        self::assertSame('batch-rt-1', $statusBody['batchId']);
        self::assertSame('completed', $statusBody['state']);
        self::assertSame(2, $statusBody['counters']['deferred']);
        self::assertGreaterThanOrEqual(2, $statusBody['events']);
        self::assertContains('C/A', $statusBody['caseIds']);
        self::assertContains('C/B', $statusBody['caseIds']);

        // Report attaches bewijzen filtered by zaakId.
        $reportResp = $this->controller()->batchReport('batch-rt-1');
        self::assertSame(Http::STATUS_OK, $reportResp->getStatus());
        $reportBody = $reportResp->getData();
        self::assertSame('batch-rt-1', $reportBody['batchId']);
        self::assertSame(['C/A', 'C/B'], $reportBody['cases']);
        // Only b-A matches (b-C is for an unrelated zaak).
        $bewijsHashes = array_column($reportBody['bewijzen'], 'hash');
        self::assertContains('aaaa', $bewijsHashes);
        self::assertNotContains('cccc', $bewijsHashes);
    }

    /**
     * Unknown jobId → 404.
     *
     * @return void
     */
    public function testBatchStatusReturns404WhenUnknown(): void
    {
        $response = $this->controller()->batchStatus('batch-unknown');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * Inspection export filters by year and forwards optional filters.
     *
     * @return void
     */
    public function testInspectionExportYearSlice(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-1', 'zaakId' => 'C/1', 'zaaktypeKey' => 'omg',
            'afsluitingsDatum' => '2026-03-12',
        ]);
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-2', 'zaakId' => 'C/2', 'zaaktypeKey' => 'omg',
            'afsluitingsDatum' => '2025-09-01',
        ]);

        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'year'        => '2026',
                    'zaaktypeKey' => '',
                    'archiefId'   => '',
                    default       => $default,
                };
            }
        );

        $response = $this->controller()->inspectionExport();
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        self::assertSame(2026, $body['year']);
        self::assertSame(1, $body['totals']['triggers']);
        self::assertCount(1, $body['rows']);
        self::assertSame('C/1', $body['rows'][0]['zaakId']);
    }

    /**
     * Missing year query param → 400.
     *
     * @return void
     */
    public function testInspectionExportRequiresYear(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $default ?? '0'
        );
        $response = $this->controller()->inspectionExport();
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Retry endpoint rejects an anonymous / unauthorised caller (fail closed).
     *
     * @return void
     */
    public function testRetryRejectsUnauthorisedCaller(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-x', 'zaakId' => 'C/X', 'status' => 'gefaald',
        ]);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(false);
        $groups->method('isInGroup')->willReturn(false);
        $controller = new ArchiefController(
            'procest',
            $this->request,
            $this->settings,
            $this->session,
            $this->logger,
            $this->batchSvc,
            $this->rollbackMgr,
            $groups,
        );
        $response = $controller->retry('tr-x');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    /**
     * Retry on an unknown trigger id → 404 (IDOR guard, no side effect).
     *
     * @return void
     */
    public function testRetryReturns404WhenTriggerUnknown(): void
    {
        $response = $this->controller()->retry('tr-does-not-exist');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * Retry on a trigger that is NOT in status `gefaald` → 409.
     *
     * @return void
     */
    public function testRetryRejectsTriggerNotInGefaald(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-ok', 'zaakId' => 'C/OK', 'status' => 'geslaagd',
        ]);
        $response = $this->controller()->retry('tr-ok');
        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
    }

    /**
     * Happy path: a `gefaald` trigger retried by an authorised archief role
     * re-submits and returns 202 with the retry summary.
     *
     * @return void
     */
    public function testRetryHappyPathReSubmits(): void
    {
        $this->objects->saveObject('procest', 'overdrachtTrigger', [
            'id' => 'tr-fail', 'zaakId' => 'C/FAIL', 'status' => 'gefaald',
        ]);
        $response = $this->controller()->retry('tr-fail');
        self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $body = $response->getData();
        self::assertTrue($body['ok']);
        self::assertSame('tr-fail', $body['triggerId']);
        self::assertSame('C/FAIL', $body['zaakId']);
        self::assertSame('geslaagd', $body['status']);

        // Trigger is flipped to geslaagd in the store.
        $trigger = $this->objects->find('tr-fail', 'procest', 'overdrachtTrigger');
        self::assertSame('geslaagd', $trigger['status']);

        // A retry-after-correction audit row was recorded.
        $auditRows = array_values($this->objects->store['overdrachtAuditLog'] ?? []);
        $found = false;
        foreach ($auditRows as $row) {
            if (($row['eventType'] ?? '') === 'retry-after-correction') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'retry-after-correction audit row must be persisted.');
    }
}//end class
