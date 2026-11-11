<?php

/**
 * Golden record of the daily share maintenance run.
 *
 * Records, per world, what the run asks the register for and every log line,
 * so a refactor cannot change when the run gives up or which shares it reports.
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

use DateTime;
use OCA\Dossiq\BackgroundJob\ShareMaintenanceJob;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * ObjectService stub for the share query.
 */
interface ShareMaintenanceObjectServiceStub {
	/**
	 * Find objects.
	 *
	 * @param array<string, mixed> $config Query config.
	 *
	 * @return array<mixed>
	 */
	public function findAll(array $config = []): array;
}//end interface

/**
 * Golden test for ShareMaintenanceJob::run.
 *
 * @covers \OCA\Dossiq\BackgroundJob\ShareMaintenanceJob
 */
class ShareMaintenanceJobGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
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
		$soon = (new DateTime('+1 day'))->format('c');
		$shares = [
			['id' => 'share-soon', 'expiresAt' => $soon, 'createdBy' => 'alice'],
			['id' => 'share-later', 'expiresAt' => (new DateTime('+30 days'))->format('c')],
			['id' => 'share-past', 'expiresAt' => (new DateTime('-1 day'))->format('c')],
			['id' => 'share-revoked', 'expiresAt' => $soon, 'revokedAt' => '2026-01-01'],
			['id' => 'share-open-ended'],
			['expiresAt' => $soon],
		];
		$configured = ['register' => '7', 'case_share_schema' => '12'];
		$worlds = [
			'openregister not installed' => ['installed' => ['files'], 'service' => 'ok', 'config' => $configured, 'shares' => $shares],
			'object service unavailable' => ['installed' => ['openregister'], 'service' => 'throw', 'config' => $configured, 'shares' => $shares],
			'no register' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => ['case_share_schema' => '12'], 'shares' => $shares],
			'no share schema' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => ['register' => '7'], 'shares' => $shares],
			'fetch fails' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => $configured, 'shares' => 'throw'],
			'no shares' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => $configured, 'shares' => []],
			'shares' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => $configured, 'shares' => $shares],
			'a share with a bad date' => ['installed' => ['openregister'], 'service' => 'ok', 'config' => $configured, 'shares' => [['id' => 'share-bad', 'expiresAt' => 'not a date'], $shares[0]]],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->events = [];
			$this->runIn(world: $world);
			$record[$name] = str_replace($soon, '<in a day>', $this->events);
		}

		$this->assertMatchesGolden(name: 'share-maintenance', value: $record);
	}//end testRunAcrossWorlds()

	/**
	 * Run the job once in one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	private function runIn(array $world): void {
		$objectService = $this->createMock(ShareMaintenanceObjectServiceStub::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($world): array {
				$this->events[] = 'findAll ' . json_encode($config);
				if ($world['shares'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return $world['shares'];
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

		$config = $world['config'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$job = new ShareMaintenanceJob(
			time: $this->createMock(ITimeFactory::class),
			settingsService: $settings,
			appManager: $appManager,
			container: $container,
			logger: $this->recordingLogger(),
		);

		$run = new ReflectionMethod(ShareMaintenanceJob::class, 'run');
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
