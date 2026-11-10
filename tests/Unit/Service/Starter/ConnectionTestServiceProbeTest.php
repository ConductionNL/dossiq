<?php

/**
 * ConnectionTestService::probe() tests.
 *
 * Pins each answer of the live probe: a value that is not a URL, an endpoint
 * that answers below 500 (reachable, a 4xx included), one that answers 500 or
 * more or no status (failed, with the status named), and a call that throws
 * (failed with its message, and logged). Times are read, never pinned.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\Starter\ConnectionTestService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ConnectionTestService::probe().
 */
class ConnectionTestServiceProbeTest extends TestCase {
	/**
	 * The options the client was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $options = [];

	/**
	 * The service over a client answering the status, or throwing when null.
	 *
	 * @param integer|null         $status The status, or null to throw.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ConnectionTestService
	 */
	private function service(?int $status, ?LoggerInterface $logger = null): ConnectionTestService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options) use ($status): IResponse {
				$this->options[] = $options + ['@uri' => $uri];
				if ($status === null) {
					throw new RuntimeException('Connection refused');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new ConnectionTestService($clients, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * The answer without its measured moment and duration.
	 *
	 * @param array<string, mixed> $result The probe's answer.
	 *
	 * @return array<string, mixed> The stable part.
	 */
	private static function stable(array $result): array {
		self::assertNotSame('', $result['measuredAt']);
		self::assertIsInt($result['responseTimeMs']);
		unset($result['measuredAt'], $result['responseTimeMs']);

		return $result;
	}//end stable()

	/**
	 * A value that is not a URL fails without a call.
	 *
	 * @return void
	 */
	public function testNotAUrlFailsWithoutACall(): void {
		$result = $this->service(200)->probe('not a url');

		self::assertSame(['state' => 'failed', 'endpoint' => 'not a url', 'status' => 0, 'reason' => 'The endpoint is not a URL.'], self::stable($result));
		self::assertSame(0, $result['responseTimeMs']);
		self::assertSame([], $this->options);
	}//end testNotAUrlFailsWithoutACall()

	/**
	 * Below 500 is reachable, a 4xx included, asked with the timeouts and without throwing on errors.
	 *
	 * @return void
	 */
	public function testBelowFiveHundredIsReachable(): void {
		foreach ([200, 401] as $status) {
			self::assertSame(
				['state' => 'reachable', 'endpoint' => 'https://broker.example/ping', 'status' => $status, 'reason' => ''],
				self::stable($this->service($status)->probe('https://broker.example/ping'))
			);
		}

		self::assertSame(
			['timeout' => 10, 'connect_timeout' => 10, 'http_errors' => false, '@uri' => 'https://broker.example/ping'],
			$this->options[0]
		);
	}//end testBelowFiveHundredIsReachable()

	/**
	 * Five hundred or more, or no status at all, fails with the status named.
	 *
	 * @return void
	 */
	public function testFiveHundredOrNoStatusFails(): void {
		foreach ([503, 0] as $status) {
			self::assertSame(
				['state' => 'failed', 'endpoint' => 'https://broker.example/ping', 'status' => $status, 'reason' => 'The endpoint answered ' . $status . '.'],
				self::stable($this->service($status)->probe('https://broker.example/ping'))
			);
		}
	}//end testFiveHundredOrNoStatusFails()

	/**
	 * A call that throws fails with its message, and is logged.
	 *
	 * @return void
	 */
	public function testAThrowingCallFailsAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq starter: a connection test failed',
			['endpoint' => 'https://broker.example/ping', 'reason' => 'Connection refused']
		);

		self::assertSame(
			['state' => 'failed', 'endpoint' => 'https://broker.example/ping', 'status' => 0, 'reason' => 'Connection refused'],
			self::stable($this->service(null, $logger)->probe('https://broker.example/ping'))
		);
	}//end testAThrowingCallFailsAndIsLogged()
}//end class
