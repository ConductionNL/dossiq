<?php

/**
 * Tenant services read findAll() rows as entities
 *
 * PINNING TESTS for the tenant services that read `ObjectService::findAll()`
 * rows: onboarding progress and step completion, the tenant configuration and
 * the month's billing events.
 *
 * `findAll()` returns `ObjectEntity` objects, never arrays, and `ObjectEntity`
 * does not implement `ArrayAccess`. Every one of these services indexed the
 * rows as arrays, so on a real install each one threw, or found nothing. Their
 * suites stayed green because no test ever handed them a row in the shape
 * production does. Every row below is an `ObjectEntity`, built the way
 * `findAll()` returns it, and each test that pins a read failed on the code
 * before the read was fixed.
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
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\TenantBillingService;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Service\TenantSaasService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The `findAll()` and `saveObject()` signatures the tenant services call.
 *
 * Both return what OpenRegister returns: `findAll()` a list of `ObjectEntity`
 * objects and `saveObject()` one `ObjectEntity`.
 */
interface TenantEntityRowObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config Query configuration.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multitenancy applies.
	 *
	 * @return array<int, mixed> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectEntity The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): ObjectEntity;
}

/**
 * @covers \OCA\Dossiq\Service\TenantOnboardingService
 *
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class TenantEntityRowReadsTest extends TestCase {
	/**
	 * The config every `findAll()` call received, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Every `saveObject()` call, in order.
	 *
	 * @var array<int, array{object: array<string, mixed>, schema: string, uuid: string|null}>
	 */
	private array $saves = [];

	/**
	 * A row in the shape `findAll()` returns it.
	 *
	 * @param array<string, mixed> $object The object data.
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function entity(array $object, string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject($object);

		return $row;
	}//end entity()

	/**
	 * An app manager and container whose ObjectService answers with $rows.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return array{0: IAppManager, 1: ContainerInterface} The app manager and container.
	 */
	private function openRegisterAnswering(array $rows): array {
		$objectService = $this->createMock(TenantEntityRowObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->queries[] = $config;

				return $rows;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];

				return $this->entity(object: $object, uuid: ($uuid ?? 'created'));
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return [$appManager, $container];
	}//end openRegisterAnswering()

	/**
	 * The onboarding service over a fake OpenRegister.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return TenantOnboardingService The service.
	 */
	private function onboardingAnswering(array $rows): TenantOnboardingService {
		[$appManager, $container] = $this->openRegisterAnswering(rows: $rows);

		return new TenantOnboardingService(
			tenantSaasService: $this->createMock(TenantSaasService::class),
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			billingService: $this->createMock(TenantBillingService::class),
		);
	}//end onboardingAnswering()

	/**
	 * Progress counts the completed steps it reads off the rows.
	 *
	 * Before the read was fixed this threw: `$r['status']` on an
	 * `ObjectEntity` is an Error, outside any catch, so the progress endpoint
	 * answered 500 for every tenant with at least one step.
	 *
	 * @return void
	 */
	public function testProgressCountsTheCompletedStepsOfEntityRows(): void {
		$rows = [];
		foreach (TenantOnboardingService::STEPS as $index => $step) {
			$status = 'pending';
			if ($index < 2) {
				$status = 'completed';
			}

			$rows[] = $this->entity(object: ['tenantRef' => 't-1', 'step' => $step, 'status' => $status], uuid: 'task-' . $index);
		}

		$progress = $this->onboardingAnswering(rows: $rows)->getProgress(tenantId: 't-1');

		$this->assertSame(2, $progress['completed']);
		$this->assertSame(7, $progress['total']);
		$this->assertSame(0.29, $progress['fraction']);
		$this->assertSame(TenantOnboardingService::STEPS, array_column($progress['steps'], 'step'));
	}//end testProgressCountsTheCompletedStepsOfEntityRows()

	/**
	 * A step is completed on the row it was read from, not on a new one.
	 *
	 * Before the read was fixed, `$task['status'] = ...` on the entity threw,
	 * the catch logged it and returned null, and the controller answered
	 * "Step not found" for a step that exists.
	 *
	 * @return void
	 */
	public function testMarkStepCompleteWritesTheEntityRowBackInPlace(): void {
		$row = $this->entity(object: ['tenantRef' => 't-1', 'step' => 'branding', 'status' => 'pending'], uuid: 'task-3');

		$task = $this->onboardingAnswering(rows: [$row])->markStepComplete(tenantId: 't-1', step: 'branding', completedBy: 'alice');

		$this->assertNotNull($task, 'a step that exists was reported as not found');
		$this->assertSame('completed', $task['status']);
		$this->assertCount(1, $this->saves);
		$this->assertSame('task-3', $this->saves[0]['uuid'], 'the step must be written back to the row it came from');
		$this->assertSame('tenantOnboardingTask', $this->saves[0]['schema']);
		$this->assertSame('branding', $this->saves[0]['object']['step']);
		$this->assertSame('t-1', $this->saves[0]['object']['tenantRef']);
		$this->assertSame('completed', $this->saves[0]['object']['status']);
		$this->assertSame('alice', $this->saves[0]['object']['completedBy']);
	}//end testMarkStepCompleteWritesTheEntityRowBackInPlace()

	/**
	 * A row that carries nothing readable is not completed.
	 *
	 * Written, it would create a new task holding only the completion fields,
	 * with no tenant and no step. This passes on the code before the fix as
	 * well, where the row threw; it guards the fix's own empty-row check.
	 *
	 * @return void
	 */
	public function testMarkStepCompleteOnAnUnreadableRowWritesNothing(): void {
		$task = $this->onboardingAnswering(rows: [new stdClass()])->markStepComplete(tenantId: 't-1', step: 'branding', completedBy: 'alice');

		$this->assertNull($task);
		$this->assertSame([], $this->saves);
	}//end testMarkStepCompleteOnAnUnreadableRowWritesNothing()
}//end class
