<?php

/**
 * ResolvesLoggedObjectService — the installed object service, or null with a logged error.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ResolvesLoggedObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Without OpenRegister nothing is resolved; a failing container is logged and answers null.
 *
 * @covers \OCA\Dossiq\Service\Support\ResolvesLoggedObjectService
 */
final class ResolvesLoggedObjectServiceTest extends TestCase {

	/**
	 * A class exposing the trait's step.
	 *
	 * @param IAppManager        $appManager The app manager.
	 * @param ContainerInterface $container  The container.
	 * @param LoggerInterface    $logger     The logger.
	 *
	 * @return object
	 */
	private function subject(IAppManager $appManager, ContainerInterface $container, LoggerInterface $logger): object {
		return new class($appManager, $container, $logger) {
			use ResolvesLoggedObjectService {
				getObjectService as public;
			}

			/**
			 * Hold the collaborators.
			 *
			 * @param IAppManager        $appManager The app manager.
			 * @param ContainerInterface $container  The container.
			 * @param LoggerInterface    $logger     The logger.
			 */
			public function __construct(
				private IAppManager $appManager,
				private ContainerInterface $container,
				private LoggerInterface $logger,
			) {
			}//end __construct()
		};
	}//end subject()

	/**
	 * Without OpenRegister installed the container is not asked.
	 *
	 * @return void
	 */
	public function testNotInstalledAnswersNull(): void {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['files']);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$subject = $this->subject(appManager: $apps, container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertNull(actual: $subject->getObjectService());
	}//end testNotInstalledAnswersNull()

	/**
	 * A failing container is logged at error level and answers null.
	 *
	 * @return void
	 */
	public function testFailingContainerIsLogged(): void {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq: Could not get ObjectService', ['exception' => 'gone']);

		$subject = $this->subject(appManager: $apps, container: $container, logger: $logger);

		self::assertNull(actual: $subject->getObjectService());
	}//end testFailingContainerIsLogged()
}//end class
