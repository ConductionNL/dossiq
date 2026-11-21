<?php

/**
 * ResolvesInstalledObjectService — OpenRegister's object service, or null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ResolvesInstalledObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The object service when installed, resolvable, or neither.
 *
 * @covers \OCA\Dossiq\Service\Support\ResolvesInstalledObjectService
 */
final class ResolvesInstalledObjectServiceTest extends TestCase {

	/**
	 * A class using the trait over the given app manager and container.
	 *
	 * @param IAppManager        $appManager The app manager.
	 * @param ContainerInterface $container  The container.
	 *
	 * @return object
	 */
	private function subject(IAppManager $appManager, ContainerInterface $container): object {
		return new class($appManager, $container) {
			use ResolvesInstalledObjectService;

			/**
			 * Constructor.
			 *
			 * @param IAppManager        $appManager The app manager.
			 * @param ContainerInterface $container  The container.
			 */
			public function __construct(
				private readonly IAppManager $appManager,
				private readonly ContainerInterface $container,
			) {
			}

			/**
			 * The trait's answer.
			 *
			 * @return mixed
			 */
			public function objectService(): mixed {
				return $this->getObjectService();
			}
		};
	}//end subject()

	/**
	 * Installed and resolvable answers the container's service.
	 *
	 * @return void
	 */
	public function testAnswersTheServiceWhenInstalled(): void {
		$service    = new stdClass();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);

		self::assertSame($service, $this->subject($appManager, $container)->objectService());
	}//end testAnswersTheServiceWhenInstalled()

	/**
	 * Not installed answers null without asking the container.
	 *
	 * @return void
	 */
	public function testAnswersNullWhenNotInstalled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['files']);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		self::assertNull($this->subject($appManager, $container)->objectService());
	}//end testAnswersNullWhenNotInstalled()

	/**
	 * A container failure answers null.
	 *
	 * @return void
	 */
	public function testAnswersNullWhenTheContainerFails(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('gone'));

		self::assertNull($this->subject($appManager, $container)->objectService());
	}//end testAnswersNullWhenTheContainerFails()
}//end class
