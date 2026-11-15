<?php

/**
 * ZgwAuthMiddlewareServicesTest
 *
 * Pins what the ZGW auth middleware needs from OpenRegister: nothing at
 * construction, and a refusal rather than a pass when the consumers cannot be
 * read. The consumer lookup itself is ApiCredentialResolver's.
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

use OCA\Dossiq\Controller\ZgwController;
use OCA\Dossiq\Middleware\ZgwAuthException;
use OCA\Dossiq\Middleware\ZgwAuthMiddleware;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCA\Dossiq\Service\ZgwAuthValidationException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister at construction and when it cannot be read.
 *
 * @covers \OCA\Dossiq\Middleware\ZgwAuthMiddleware
 * @covers \OCA\Dossiq\Middleware\ZgwAuthException
 * @uses   \OCA\Dossiq\Service\Zgw\ApiCredentialResolver
 */
class ZgwAuthMiddlewareServicesTest extends TestCase {

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
	 * Building the middleware asks the server container for nothing and logs nothing.
	 *
	 * @return void
	 */
	public function testConstructionReadsNothingFromTheServer(): void {
		$server = new class {
			/**
			 * The services asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Record the request and resolve nothing.
			 *
			 * @param string $name The service.
			 *
			 * @return object
			 */
			public function get(string $name): object {
				$this->asked[] = $name;
				throw new \RuntimeException('Could not resolve '.$name);
			}//end get()
		};
		\OC::$server = $server;

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		new ZgwAuthMiddleware($this->createMock(IRequest::class), $this->createMock(ApiCredentialResolver::class), $logger);

		$this->assertSame([], $server->asked);
	}//end testConstructionReadsNothingFromTheServer()

	/**
	 * When the consumers cannot be read the request is refused with 401 and
	 * the reason is logged, never passed through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
	 */
	public function testUnreadableConsumersAreRefusedWith401(): void {
		$resolver = $this->createMock(ApiCredentialResolver::class);
		$resolver->method('resolve')->willThrowException(new ZgwAuthValidationException('Authorization service is unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('ZGW auth failed: Authorization service is unavailable');

		$middleware = new ZgwAuthMiddleware($this->createMock(IRequest::class), $resolver, $logger);

		try {
			$middleware->beforeController($this->createMock(ZgwController::class), 'index');
			$this->fail('an unreadable consumer store must refuse');
		} catch (ZgwAuthException $refused) {
			$this->assertSame(401, $refused->getStatusCode());
			$this->assertSame('The authorization service is unavailable.', $refused->getMessage());
		}
	}//end testUnreadableConsumersAreRefusedWith401()
}//end class
