<?php

/**
 * TenderVisibilityService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/leverancier-zaakportaal-05-tender-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\TenderVisibilityService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenderVisibilityService
 */
class TenderVisibilityServiceTest extends TestCase
{
    private TenderVisibilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $scope = $this->createMock(SupplierScopeService::class);
        $this->svc = new TenderVisibilityService(
            scopeService: $scope,
            appManager: $this->createMock(IAppManager::class),
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testAppealDeadlineNullForNonRejected(): void
    {
        $this->assertNull($this->svc->getAppealDeadline(['status' => 'evaluating']));
        $this->assertNull($this->svc->getAppealDeadline(['status' => 'awarded']));
    }

    public function testAppealDeadlineUsesExplicitWhenSet(): void
    {
        $r = $this->svc->getAppealDeadline(['status' => 'rejected', 'appealDeadline' => '2026-03-01']);
        $this->assertSame('2026-03-01', $r);
    }

    public function testAppealDeadlineComputesFromSubmitted(): void
    {
        $r = $this->svc->getAppealDeadline(['status' => 'rejected', 'submittedDate' => '2026-01-01']);
        $this->assertSame('2026-01-21', $r);
    }

    public function testCanAppealRespectsWindow(): void
    {
        $past = ['status' => 'rejected', 'appealDeadline' => '2000-01-01'];
        $future = ['status' => 'rejected', 'appealDeadline' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')];
        $this->assertFalse($this->svc->canAppeal($past));
        $this->assertTrue($this->svc->canAppeal($future));
    }

    public function testEvaluationReportDownloadableOnlyForAwardedOrRejectedWithRef(): void
    {
        $this->assertFalse($this->svc->isEvaluationReportDownloadable(['status' => 'submitted', 'evaluationReportRef' => 'r1']));
        $this->assertFalse($this->svc->isEvaluationReportDownloadable(['status' => 'rejected']));
        $this->assertTrue($this->svc->isEvaluationReportDownloadable(['status' => 'rejected', 'evaluationReportRef' => 'r1']));
        $this->assertTrue($this->svc->isEvaluationReportDownloadable(['status' => 'awarded', 'evaluationReportRef' => 'r2']));
    }

    public function testListTendersReturnsEmptyWhenOrUnavailable(): void
    {
        // SupplierScopeService is mocked → listSupplierObjects() returns empty.
        $this->assertSame([], $this->svc->listTenders('s-1'));
    }
}
