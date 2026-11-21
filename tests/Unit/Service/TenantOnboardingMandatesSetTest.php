<?php

/**
 * TenantOnboardingService: the "Mandates set" step follows the mandate register
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Service\TenantService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The checklist marks "Mandates set" once the tenant has an active mandate.
 *
 * @covers \OCA\Dossiq\Service\TenantOnboardingService
 * @uses   \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantOnboardingMandatesSetTest extends TestCase {
	private OnboardingSteps&MockObject $steps;

	private MandaatCheckService&MockObject $mandates;

	protected function setUp(): void {
		parent::setUp();
		$this->steps = $this->createMock(OnboardingSteps::class);
		$this->mandates = $this->createMock(MandaatCheckService::class);
	}

	/**
	 * Build the service.
	 *
	 * @param bool $withMandates Whether a mandate service is bound.
	 *
	 * @return TenantOnboardingService The service.
	 */
	private function service(bool $withMandates = true): TenantOnboardingService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin-anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TenantOnboardingService(
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			billingService: $this->createMock(TenantBillingService::class),
			tenantService: $this->createMock(TenantService::class),
			steps: $this->steps,
			userSession: $session,
			rowNormaliser: new OpenRegisterRowNormaliser(),
			mandates: $withMandates === true ? $this->mandates : null,
		);
	}

	/**
	 * Two stored steps: contract done, mandates in the given status.
	 *
	 * @param string $status The mandate step's stored status.
	 *
	 * @return void
	 */
	private function storedSteps(string $status): void {
		$this->steps->method('forTenant')->with('tenant-1', 'admin-anna')->willReturn(
			[
				'contract' => ['id' => 't-1', 'step' => 'contract', 'status' => 'completed', 'completedBy' => 'marieke', 'completedAt' => '2026-10-01'],
				'mandate_import' => ['id' => 't-2', 'step' => 'mandate_import', 'status' => $status, 'completedBy' => '', 'completedAt' => ''],
			]
		);
	}

	public function testAnActiveMandateMarksThePendingStepDone(): void {
		$this->storedSteps(status: 'pending');
		$this->mandates->expects($this->once())
			->method('hasActiveMandateFor')
			->with('tenant-1', $this->isInstanceOf(DateTimeImmutable::class))
			->willReturn(true);
		$this->steps->expects($this->never())->method('complete');

		$progress = $this->service()->getProgress('tenant-1');

		$this->assertSame('completed', $progress['steps'][1]['status']);
		$this->assertSame('mandate_import', $progress['steps'][1]['step']);
		$this->assertSame(2, $progress['completed']);
		$this->assertSame(7, $progress['total']);
		$this->assertSame(0.29, $progress['fraction']);
	}

	public function testWithoutAnActiveMandateTheStepKeepsItsState(): void {
		$this->storedSteps(status: 'in_progress');
		$this->mandates->method('hasActiveMandateFor')->willReturn(false);

		$progress = $this->service()->getProgress('tenant-1');

		$this->assertSame('in_progress', $progress['steps'][1]['status']);
		$this->assertSame(1, $progress['completed']);
	}

	public function testACompletedOrSkippedStepIsNotAskedAbout(): void {
		$this->storedSteps(status: 'skipped');
		$this->mandates->expects($this->never())->method('hasActiveMandateFor');

		$progress = $this->service()->getProgress('tenant-1');

		$this->assertSame('skipped', $progress['steps'][1]['status']);
		$this->assertSame(1, $progress['completed']);
	}

	public function testWithoutAMandateServiceTheStepKeepsItsState(): void {
		$this->storedSteps(status: 'pending');

		$progress = $this->service(withMandates: false)->getProgress('tenant-1');

		$this->assertSame('pending', $progress['steps'][1]['status']);
	}

	public function testATenantWithoutTheStepIsNotAskedAbout(): void {
		$this->steps->method('forTenant')->willReturn(
			['contract' => ['id' => 't-1', 'step' => 'contract', 'status' => 'pending', 'completedBy' => '', 'completedAt' => '']]
		);
		$this->mandates->expects($this->never())->method('hasActiveMandateFor');

		$progress = $this->service()->getProgress('tenant-1');

		$this->assertCount(1, $progress['steps']);
		$this->assertSame(0, $progress['completed']);
	}
}
