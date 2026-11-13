<?php

/**
 * ZgwAuthMiddlewareServicesTest
 *
 * Pins how the ZGW auth middleware finds OpenRegister's consumer mapper: it
 * asks the server container for it by name, and an instance without
 * OpenRegister is logged as a warning instead of breaking construction.
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Middleware
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-autorisaties-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Middleware;

use OCA\Dossiq\Middleware\ZgwAuthMiddleware;
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolving OpenRegister's consumer mapper at construction.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 */
class ZgwAuthMiddlewareServicesTest extends TestCase {

	/**
	 * The service the middleware resolves.
	 *
	 * @var string
	 */
	private const CONSUMER_MAPPER = 'OCA\OpenRegister\Db\ConsumerMapper';

	/**
	 * The server container before the test.
	 *
	 * @var mixed
	 */
	private mixed $server = null;

	/**
	 * Keep the server so tearDown can put it back.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->server = \OC::$server;
	}//end setUp()

	/**
	 * Put the server back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\OC::$server = $this->server;
		parent::tearDown();
	}//end tearDown()

	/**
	 * A server that cannot resolve the consumer mapper is logged as a warning,
	 * with the reason, and the middleware is still built.
	 *
	 * @return void
	 */
	public function testAMissingOpenRegisterIsLoggedAndConstructionSucceeds(): void {
		\OC::$server = new class {
			/**
			 * Resolve nothing.
			 *
			 * @param string $name The service.
			 *
			 * @return object
			 */
			public function get(string $name): object {
				throw new RuntimeException('Could not resolve '.$name);
			}//end get()
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'ZgwAuthMiddleware: OpenRegister auth services not available',
				['exception' => 'Could not resolve '.self::CONSUMER_MAPPER]
			);

		$middleware = new ZgwAuthMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(ZgwJwtValidator::class),
			$logger,
		);

		$this->assertInstanceOf(ZgwAuthMiddleware::class, $middleware);
	}//end testAMissingOpenRegisterIsLoggedAndConstructionSucceeds()

	/**
	 * A server that resolves the consumer mapper is asked for it by name, once,
	 * and nothing is logged.
	 *
	 * @return void
	 */
	public function testTheConsumerMapperIsResolvedByNameWithoutAWarning(): void {
		$server = new class {
			/**
			 * The services asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Resolve any service to a placeholder.
			 *
			 * @param string $name The service.
			 *
			 * @return object
			 */
			public function get(string $name): object {
				$this->asked[] = $name;
				return new \stdClass();
			}//end get()
		};
		\OC::$server = $server;

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		new ZgwAuthMiddleware(
			$this->createMock(IRequest::class),
			$this->createMock(ZgwJwtValidator::class),
			$logger,
		);

		$this->assertSame([self::CONSUMER_MAPPER], $server->asked);
	}//end testTheConsumerMapperIsResolvedByNameWithoutAWarning()
}//end class
