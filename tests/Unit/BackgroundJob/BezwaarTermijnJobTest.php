<?php

/**
 * BezwaarTermijnJob Unit Tests.
 *
 * Verifies that the daily job archives a beschikking when its bezwaartermijn
 * has lapsed without a bezwaarschrift, skips it when a bezwaar was received,
 * deactivates triggers (idempotency), and is a no-op without OpenRegister.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
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

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\BezwaarTermijnJob;
use OCA\Procest\Service\BeschikkingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Tests\Unit\Service\FakeObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/BeschikkingServiceTest.php';

/**
 * Unit tests for BezwaarTermijnJob.
 *
 * @covers \OCA\Procest\BackgroundJob\BezwaarTermijnJob
 */
class BezwaarTermijnJobTest extends TestCase {
	/**
	 * The in-memory object store.
	 *
	 * @var FakeObjectService
	 */
	private FakeObjectService $objects;

	/**
	 * The beschikking service mock.
	 *
	 * @var BeschikkingService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private BeschikkingService $decisionService;

	/**
	 * The settings service mock.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settings;

	/**
	 * The app manager mock.
	 *
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppManager $appManager;

	/**
	 * The job under test.
	 *
	 * @var BezwaarTermijnJob
	 */
	private BezwaarTermijnJob $job;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeObjectService();
		$this->decisionService = $this->createMock(BeschikkingService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'procest',
					'bezwaar_trigger_schema' => 'bezwaarTrigger',
					default => '',
				};
			},
		);

		$this->job = new BezwaarTermijnJob(
			$timeFactory,
			$this->decisionService,
			$this->settings,
			$this->appManager,
			$logger,
		);
	}//end setUp()

	/**
	 * Invoke the protected run() method.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$method = new \ReflectionMethod(BezwaarTermijnJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}//end runJob()

	/**
	 * A lapsed trigger without bezwaar archives the beschikking and is deactivated.
	 *
	 * @return void
	 */
	public function testLapsedTriggerArchives(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
		$this->objects->saveObject('procest', 'bezwaarTrigger', [
			'id' => 'trig-1',
			'beschikkingId' => 'besch-1',
			'bezwaarOntvangen' => false,
			'archiefTriggerActief' => true,
			'archiefDatum' => $yesterday,
		]);

		$this->decisionService->expects($this->once())
			->method('archive')
			->with('besch-1')
			->willReturn(['id' => 'besch-1', 'huidigeStatus' => 'gearchiveerd']);

		$this->runJob();

		$trigger = $this->objects->find('trig-1', 'procest', 'bezwaarTrigger');
		$this->assertFalse($trigger['archiefTriggerActief']);
	}//end testLapsedTriggerArchives()

	/**
	 * A trigger with a received bezwaar is not archived.
	 *
	 * @return void
	 */
	public function testBezwaarReceivedSkipsArchival(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
		$this->objects->saveObject('procest', 'bezwaarTrigger', [
			'id' => 'trig-2',
			'beschikkingId' => 'besch-2',
			'bezwaarOntvangen' => true,
			'archiefTriggerActief' => true,
			'archiefDatum' => $yesterday,
		]);

		$this->decisionService->expects($this->never())->method('archive');

		$this->runJob();

		$trigger = $this->objects->find('trig-2', 'procest', 'bezwaarTrigger');
		$this->assertFalse($trigger['archiefTriggerActief']);
	}//end testBezwaarReceivedSkipsArchival()

	/**
	 * A future archiefDatum is left untouched.
	 *
	 * @return void
	 */
	public function testFutureTriggerUntouched(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$tomorrow = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
		$this->objects->saveObject('procest', 'bezwaarTrigger', [
			'id' => 'trig-3',
			'beschikkingId' => 'besch-3',
			'bezwaarOntvangen' => false,
			'archiefTriggerActief' => true,
			'archiefDatum' => $tomorrow,
		]);

		$this->decisionService->expects($this->never())->method('archive');

		$this->runJob();

		$trigger = $this->objects->find('trig-3', 'procest', 'bezwaarTrigger');
		$this->assertTrue($trigger['archiefTriggerActief']);
	}//end testFutureTriggerUntouched()

	/**
	 * Without OpenRegister installed the job is a no-op.
	 *
	 * @return void
	 */
	public function testNoOpWithoutOpenRegister(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['procest']);
		$this->decisionService->expects($this->never())->method('archive');

		$this->runJob();

		$this->assertTrue(true);
	}//end testNoOpWithoutOpenRegister()
}//end class
