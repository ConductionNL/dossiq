<?php

/**
 * EngineInboxQuery engine resolution tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineInboxQuery
 * @uses \OCA\Dossiq\Service\Task\InboxCriteria
 */
class EngineInboxQueryResolutionTest extends TestCase {

	/**
	 * Without OpenRegister the container is never asked and the read is empty, saying why.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheContainerIsNotAsked(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects(self::atLeastOnce())->method('isOpenRegisterAvailable')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::never())->method('error');

		$query = new EngineInboxQuery(settings: $settings, container: $container, logger: $logger);

		self::assertSame([], $query->rows(criteria: [], limit: 10, failure: ['unused', []]));
		self::assertSame('the task engine is not available', $query->lastError());
		self::assertSame(0, $query->total(criteria: [], failure: ['unused', []]));
	}//end testWithoutOpenRegisterTheContainerIsNotAsked()

	/**
	 * An OpenRegister whose inbox service is absent or cannot be built is the same: no engine.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterWithoutAUsableInboxServiceIsNoEngine(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$query = new EngineInboxQuery(settings: $settings, container: $container, logger: $this->createMock(LoggerInterface::class));

		self::assertSame([], $query->rows(criteria: [], limit: 10, failure: ['unused', []]));
		self::assertSame('the task engine is not available', $query->lastError());
	}//end testAnOpenRegisterWithoutAUsableInboxServiceIsNoEngine()
}//end class
