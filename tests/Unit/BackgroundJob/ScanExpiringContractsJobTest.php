<?php

/**
 * ScanExpiringContractsJob Unit Tests
 *
 * Verifies the nightly contract-expiry sweep: the job is a no-op when
 * OpenRegister is absent, swallows scan failures (never re-throws), and
 * delegates to ContractRenewalService::scanAndFlagExpiring().
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\ScanExpiringContractsJob;
use OCA\Procest\Service\ContractRenewalService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ScanExpiringContractsJob.
 *
 * @covers \OCA\Procest\BackgroundJob\ScanExpiringContractsJob
 */
class ScanExpiringContractsJobTest extends TestCase
{
    /**
     * Build a job with the given app-manager + renewal-service mocks.
     *
     * @param IAppManager            $appManager App manager mock.
     * @param ContractRenewalService $renewal    Renewal service mock.
     * @param LoggerInterface|null   $logger     Optional logger override.
     *
     * @return ScanExpiringContractsJob
     */
    private function buildJob(
        IAppManager $appManager,
        ContractRenewalService $renewal,
        ?LoggerInterface $logger=null,
    ): ScanExpiringContractsJob {
        return new ScanExpiringContractsJob(
            time: $this->createMock(ITimeFactory::class),
            renewal: $renewal,
            appManager: $appManager,
            logger: $logger ?? $this->createMock(LoggerInterface::class),
        );
    }//end buildJob()

    /**
     * Invoke the protected run() via reflection.
     *
     * @param ScanExpiringContractsJob $job Job under test.
     *
     * @return void
     */
    private function runJob(ScanExpiringContractsJob $job): void
    {
        $method = new \ReflectionMethod(ScanExpiringContractsJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }//end runJob()

    public function testNoOpWhenOpenRegisterAbsent(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['files']);

        $renewal = $this->createMock(ContractRenewalService::class);
        $renewal->expects($this->never())->method('scanAndFlagExpiring');

        $this->runJob($this->buildJob($appManager, $renewal));
        $this->assertTrue(true);
    }//end testNoOpWhenOpenRegisterAbsent()

    public function testDelegatesToServiceWhenOpenRegisterPresent(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['openregister']);

        $renewal = $this->createMock(ContractRenewalService::class);
        $renewal->expects($this->once())->method('scanAndFlagExpiring')
            ->with($this->isType('int'))
            ->willReturn(['scanned' => 5, 'flagged' => 2]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('Procest contract expiry scan finished', ['scanned' => 5, 'flagged' => 2]);

        $this->runJob($this->buildJob($appManager, $renewal, $logger));
    }//end testDelegatesToServiceWhenOpenRegisterPresent()

    public function testSwallowsServiceFailure(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getInstalledApps')->willReturn(['openregister']);

        $renewal = $this->createMock(ContractRenewalService::class);
        $renewal->method('scanAndFlagExpiring')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        // Must not throw.
        $this->runJob($this->buildJob($appManager, $renewal, $logger));
        $this->assertTrue(true);
    }//end testSwallowsServiceFailure()
}//end class
