<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Support\OptionalService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * A missing class, a failing container and a non-object all answer null.
 *
 * @covers \OCA\Dossiq\Support\OptionalService
 */
class OptionalServiceTest extends TestCase {

	public function testUnknownClassNeverReachesTheContainer(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertNull(OptionalService::from(container: $container, fqn: 'OCA\\Nowhere\\Missing'));
	}

	public function testResolvedServiceIsReturned(): void {
		$service = new stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(stdClass::class)->willReturn($service);

		$this->assertSame($service, OptionalService::from(container: $container, fqn: stdClass::class));
	}

	public function testFailingContainerAnswersNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('down'));

		$this->assertNull(OptionalService::from(container: $container, fqn: stdClass::class));
	}

	public function testNonObjectAnswersNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn('not an object');

		$this->assertNull(OptionalService::from(container: $container, fqn: stdClass::class));
	}
}
