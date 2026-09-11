<?php

/**
 * ResetMonthlyQuotasJob Unit Tests
 *
 * The job reads every tenantQuota row through `ObjectService::findAll()`, which
 * returns `ObjectEntity` objects. It indexed them as arrays, outside any catch,
 * so it died on the first row of every run and never reset a quota. That was
 * harmless only while the quota lookup was broken too and allowed everything.
 * Once the lookup reads rows, a counter that is never reset keeps a `block`
 * quota refusing long after its window has passed, so the two are fixed
 * together and this pins the job to the row shape OpenRegister returns.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-quotas/spec.md#requirement-monthly-quota-reset-req-005-d
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The `findAll()` and `saveObject()` seam the job and the quota service call.
 *
 * Declared here rather than as `\stdClass` + `addMethods()`, which PHPUnit 10
 * deprecates.
 */
interface ResetMonthlyQuotasObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config        Query configuration.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether multitenancy applies.
	 *
	 * @return array<int, mixed> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   The object data.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string|null          $uuid     The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}

/**
 * @covers \OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob
 *
 * @uses \OCA\Dossiq\Service\TenantQuotaService
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class ResetMonthlyQuotasJobTest extends TestCase {
	/**
	 * Every `saveObject()` call, in order.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saves = [];

	/**
	 * A tenantQuota row in the shape `findAll()` returns it.
	 *
	 * @param string $uuid         The row's uuid.
	 * @param int    $currentUsage The usage counted so far.
	 * @param string $resetAt      When the window resets.
	 *
	 * @return ObjectEntity The row.
	 */
	private function quotaRow(string $uuid, int $currentUsage, string $resetAt): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setObject(
			[
				'tenantRef' => 'tenant-a',
				'quotaType' => 'cases_per_month',
				'limit' => 100,
				'currentUsage' => $currentUsage,
				'enforcement' => 'block',
				'resetAt' => $resetAt,
			]
		);

		return $row;
	}

	/**
	 * Run the job once over the given rows.
	 *
	 * @param array<int, mixed> $rows What `findAll()` returns.
	 *
	 * @return void
	 */
	private function runOver(array $rows): void {
		$objectService = $this->createMock(ResetMonthlyQuotasObjectServiceStub::class);
		$objectService->method('findAll')->willReturn($rows);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->saves[] = ['object' => $object, 'uuid' => $uuid];

				return $object;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createMock(LoggerInterface::class);

		$job = new ResetMonthlyQuotasJob(
			time: $this->createMock(ITimeFactory::class),
			quotaService: new TenantQuotaService(appManager: $appManager, container: $container, logger: $logger),
			appManager: $appManager,
			container: $container,
			logger: $logger,
		);

		// The run() method is protected; the job framework is what calls it.
		$run = new \ReflectionMethod(ResetMonthlyQuotasJob::class, 'run');
		$run->invoke($job, null);
	}

	/**
	 * A due quota is reset on the row it was read from; one not yet due is left alone.
	 *
	 * @return void
	 */
	public function testADueQuotaIsResetInPlaceAndOneNotDueIsLeftAlone(): void {
		$this->runOver(
			rows: [
				$this->quotaRow(uuid: 'quota-due', currentUsage: 100, resetAt: '2020-01-01T00:00:00+00:00'),
				$this->quotaRow(uuid: 'quota-not-due', currentUsage: 10, resetAt: '2099-01-01T00:00:00+00:00'),
			]
		);

		$this->assertCount(1, $this->saves);
		$this->assertSame('quota-due', $this->saves[0]['uuid']);
		$this->assertSame(0, $this->saves[0]['object']['currentUsage']);
		$this->assertGreaterThan(time(), strtotime((string)$this->saves[0]['object']['resetAt']));
		$this->assertSame('tenant-a', $this->saves[0]['object']['tenantRef']);
	}
}
