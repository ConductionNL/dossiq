<?php

/**
 * Unit tests for carrying a tenant's legacy onboarding rows onto the task engine.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Task\OnboardingSteps;
use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Service\TenantService;
use OCP\App\IAppManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\TenantOnboardingService
 * @uses   \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantOnboardingLegacyRowsTest extends TestCase {

	/**
	 * The engine steps.
	 *
	 * @var OnboardingSteps&MockObject
	 */
	private OnboardingSteps&MockObject $steps;

	/**
	 * Every open() the service made, as [step, status, metadata].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, string>}>
	 */
	private array $opened = [];

	/**
	 * Set up the engine steps that record what is opened.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->steps = $this->createMock(originalClassName: OnboardingSteps::class);
		$this->steps->method('open')->willReturnCallback(
			function (string $tenantId, string $step, string $title, string $status, ?string $actor, array $metadata = []): string {
				$this->opened[] = [$step, $status, $metadata];
				return 'task-' . $step;
			}
		);
	}//end setUp()

	/**
	 * The service over an object service whose findAll() answers the given rows, or throws.
	 *
	 * @param array<int, mixed>|null $rows The rows, or null to throw.
	 *
	 * @return TenantOnboardingService The service.
	 */
	private function service(?array $rows): TenantOnboardingService {
		$objectService = new class($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, mixed>|null $rows The rows, or null to throw.
			 */
			public function __construct(private readonly ?array $rows) {
			}

			/**
			 * The rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config): array {
				if ($this->rows === null) {
					throw new RuntimeException(message: 'store down');
				}

				return $this->rows;
			}
		};
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(value: ['openregister']);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(value: $objectService);

		return new TenantOnboardingService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			billingService: $this->createMock(originalClassName: TenantBillingService::class),
			tenantService: $this->createMock(originalClassName: TenantService::class),
			steps: $this->steps,
			userSession: $this->createMock(originalClassName: IUserSession::class),
		);
	}//end service()

	/**
	 * A tenant with only legacy rows gets each known step opened with its status and kept fields, then read again.
	 *
	 * @return void
	 */
	public function testLegacyRowsOfTheTenantAreCarriedOntoTheEngine(): void {
		$this->steps->method('forTenant')->willReturnOnConsecutiveCalls(
			[],
			['contract' => ['id' => 'task-contract', 'status' => 'completed']]
		);

		$progress = $this->service(
			rows: [
				['uuid' => 'row-1', 'tenantRef' => 'tenant-a', 'step' => 'contract', 'status' => 'completed', 'completedBy' => 'anna', 'completedAt' => '', 'blockedReason' => ''],
				['uuid' => 'row-2', 'tenantRef' => 'tenant-a', 'step' => 'branding'],
				['uuid' => 'row-3', 'tenantRef' => 'tenant-a', 'step' => 'not-a-step', 'status' => 'completed'],
				['uuid' => 'row-4', 'tenantRef' => 'tenant-b', 'step' => 'sso_setup'],
			]
		)->getProgress(tenantId: 'tenant-a');

		self::assertSame(
			expected: [
				['contract', 'completed', ['onboardingRow' => 'row-1', 'completedBy' => 'anna']],
				['branding', 'pending', ['onboardingRow' => 'row-2']],
			],
			actual: $this->opened
		);
		self::assertSame(expected: 1, actual: $progress['completed']);
	}//end testLegacyRowsOfTheTenantAreCarriedOntoTheEngine()

	/**
	 * Nothing carried, or a failed read, opens nothing and reads the engine once.
	 *
	 * @return void
	 */
	public function testNoLegacyRowAndAFailedReadOpenNothing(): void {
		$this->steps->expects($this->exactly(2))->method('forTenant')->willReturn(value: []);

		self::assertSame(expected: 0, actual: $this->service(rows: [])->getProgress(tenantId: 'tenant-a')['completed']);
		self::assertSame(expected: 0, actual: $this->service(rows: null)->getProgress(tenantId: 'tenant-a')['completed']);
		self::assertSame(expected: [], actual: $this->opened);
	}//end testNoLegacyRowAndAFailedReadOpenNothing()

	/**
	 * A tenant that already has engine steps is not read for legacy rows.
	 *
	 * @return void
	 */
	public function testATenantWithEngineStepsIsNotCarriedAgain(): void {
		$this->steps->method('forTenant')->willReturn(value: ['contract' => ['id' => 'task-contract', 'status' => 'pending']]);

		$this->service(rows: [['uuid' => 'row-1', 'tenantRef' => 'tenant-a', 'step' => 'branding']])->getProgress(tenantId: 'tenant-a');

		self::assertSame(expected: [], actual: $this->opened);
	}//end testATenantWithEngineStepsIsNotCarriedAgain()
}//end class
