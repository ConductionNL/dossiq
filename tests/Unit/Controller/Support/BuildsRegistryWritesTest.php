<?php

/**
 * The registry write helper is wired to the controller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\BuildsRegistryWrites;
use OCA\Dossiq\Controller\Support\RegistryObjectWrite;
use OCA\Dossiq\Kernel\Contracts\MandaatRegistryServiceInterface;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\BuildsRegistryWrites
 */
class BuildsRegistryWritesTest extends TestCase {

	public function testWiring(): void {
		$registry = $this->createMock(MandaatRegistryServiceInterface::class);
		$request = $this->createMock(IRequest::class);
		$logger = $this->createMock(LoggerInterface::class);
		$host = new class ($registry, $request, $logger) {
			use BuildsRegistryWrites;

			public function __construct(
				private readonly MandaatRegistryServiceInterface $registry,
				private readonly IRequest $request,
				private readonly LoggerInterface $logger,
			) {
			}

			public function built(): RegistryObjectWrite {
				return $this->writes();
			}
		};

		$writes = $host->built();
		$this->assertSame($registry, $writes->registry);
		$this->assertSame($request, $writes->request);
		$this->assertSame($logger, $writes->logger);
	}
}
