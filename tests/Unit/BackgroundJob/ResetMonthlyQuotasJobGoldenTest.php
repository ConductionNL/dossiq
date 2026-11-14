<?php

/**
 * Golden record of the monthly quota reset run.
 *
 * Records, per world, what the run asks the register for, what it writes back
 * and every log line, so a refactor cannot change when the run gives up or
 * what it reports.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob;
use OCA\Dossiq\Service\OrganisationQuotaLimits;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\TenantQuotaService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Loosely typed ObjectService stub, so a non-array answer can be recorded.
 */
interface ResetMonthlyQuotasLooseObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config Query config.
	 *
	 * @return mixed
	 */
	public function findAll(array $config = []): mixed;

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   Object data.
	 * @param string               $register Register.
	 * @param string               $schema   Schema.
	 * @param string|null          $uuid     Object UUID.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): mixed;
}//end interface

/**
 * Golden test for ResetMonthlyQuotasJob::work.
 *
 * @covers \OCA\Dossiq\BackgroundJob\ResetMonthlyQuotasJob
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 * @uses \OCA\Dossiq\Service\TenantQuotaService
 */
class ResetMonthlyQuotasJobGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What the run did in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the run distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testRunAcrossWorlds(): void {
		$due = $this->quotaRow(uuid: 'quota-due', currentUsage: 100, resetAt: '2020-01-01T00:00:00+00:00');
		$dueUnused = $this->quotaRow(uuid: 'quota-due-unused', currentUsage: 0, resetAt: '2020-01-01T00:00:00+00:00');
		$notDue = $this->quotaRow(uuid: 'quota-not-due', currentUsage: 10, resetAt: '2099-01-01T00:00:00+00:00');
		$worlds = [
			'openregister not installed' => ['installed' => ['files'], 'service' => 'ok', 'rows' => [$due]],
			'object service unavailable' => ['installed' => ['openregister'], 'service' => 'throw', 'rows' => [$due]],
			'fetch fails' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => 'throw'],
			'fetch answers no list' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => 'nothing'],
			'no quotas' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => []],
			'nothing due' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => [$notDue]],
			'a due quota that was unused' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => [$dueUnused]],
			'one due, one not' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => [$due, $notDue]],
			'a row that is not a quota' => ['installed' => ['openregister'], 'service' => 'ok', 'rows' => ['not-a-row', $due]],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->events = [];
			$this->runIn(world: $world);
			$record[$name] = $this->events;
		}

		$this->assertMatchesGolden(name: 'reset-monthly-quotas', value: $record);
	}//end testRunAcrossWorlds()

	/**
	 * A tenant quota row.
	 *
	 * @param string $uuid         Row UUID.
	 * @param int    $currentUsage Usage so far.
	 * @param string $resetAt      When the quota resets.
	 *
	 * @return ObjectEntity
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
	}//end quotaRow()

	/**
	 * Run the job once in one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	private function runIn(array $world): void {
		$objectService = $this->createMock(ResetMonthlyQuotasLooseObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($world): mixed {
				$this->events[] = 'findAll ' . json_encode($config);
				if ($world['rows'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return $world['rows'] === 'nothing' ? null : $world['rows'];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->events[] = 'save ' . $uuid . ' usage ' . $object['currentUsage'];
				return $object;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($world['installed']);
		$container = $this->createMock(ContainerInterface::class);
		if ($world['service'] === 'throw') {
			$container->method('get')->willThrowException(new RuntimeException('no service'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$serviceAccount = $this->createMock(BackgroundServiceAccount::class);
		$serviceAccount->method('runAs')->willReturnCallback(static fn (callable $operation): mixed => $operation());

		$job = new ResetMonthlyQuotasJob(
			time: $this->createMock(ITimeFactory::class),
			quotaService: new TenantQuotaService(
				appManager: $appManager,
				container: $container,
				logger: $this->createMock(LoggerInterface::class),
				organisationLimits: $this->createMock(OrganisationQuotaLimits::class),
			),
			appManager: $appManager,
			container: $container,
			logger: $this->recordingLogger(),
			serviceAccount: $serviceAccount,
		);

		$run = new ReflectionMethod(ResetMonthlyQuotasJob::class, 'run');
		$run->invoke($job, null);
	}//end runIn()

	/**
	 * A logger that records each line with its level and context.
	 *
	 * @return LoggerInterface
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->events[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return $logger;
	}//end recordingLogger()
}//end class
