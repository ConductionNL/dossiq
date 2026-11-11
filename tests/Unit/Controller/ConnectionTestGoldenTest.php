<?php

/**
 * Golden record of the StUF endpoint connection test.
 *
 * Records, per world, the status and body the endpoint answers with, what it
 * asks the register for, what it probes and every log line.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ConnectionTestController;
use OCA\Dossiq\Service\Starter\ConnectionTestService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for ConnectionTestController::testStufEndpoint.
 *
 * @covers \OCA\Dossiq\Controller\ConnectionTestController
 */
class ConnectionTestGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * What happened in the current world.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testConnectionTestAcrossWorlds(): void {
		$worlds = [
			'register fails' => 'throw',
			'unknown endpoint' => [],
			'not an endpoint row' => ['ep-1'],
			'endpoint without a URL' => [['id' => 'ep-1', 'name' => 'Halfweg']],
			'endpoint with an empty URL' => [['id' => 'ep-1', 'endpointUrl' => '']],
			'configured endpoint' => [['id' => 'ep-1', 'endpointUrl' => 'https://stuf.example/zaken']],
		];

		$record = [];
		foreach ($worlds as $name => $rows) {
			$this->events = [];
			$response = $this->controllerFor(rows: $rows)->testStufEndpoint(endpointId: 'ep-1');
			$body = $response->getData();
			if (isset($body['measuredAt']) === true) {
				$body['measuredAt'] = (strtotime($body['measuredAt']) !== false) ? 'a timestamp' : $body['measuredAt'];
			}

			$record[$name] = ['status' => $response->getStatus(), 'body' => $body, 'events' => $this->events];
		}

		$this->assertMatchesGolden(name: 'connection-test-stuf', value: $record);
	}//end testConnectionTestAcrossWorlds()

	/**
	 * Build a controller whose register answers with $rows.
	 *
	 * @param array<mixed>|string $rows The register's rows, or 'throw'.
	 *
	 * @return ConnectionTestController
	 */
	private function controllerFor(array|string $rows): ConnectionTestController {
		$register = $this->createMock(StufRegisterAccess::class);
		$register->method('findAll')->willReturnCallback(
			function (string $schema, array $filters = [], int $limit = 100) use ($rows): array {
				$this->events[] = 'findAll ' . $schema . ' ' . json_encode($filters) . ' limit ' . $limit;
				if ($rows === 'throw') {
					throw new RuntimeException('no register');
				}

				return $rows;
			}
		);

		$probe = $this->createMock(ConnectionTestService::class);
		$probe->method('probe')->willReturnCallback(
			function (string $endpoint): array {
				$this->events[] = 'probe ' . $endpoint;
				return ['state' => 'ok', 'endpoint' => $endpoint];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->events[] = 'error: ' . $message;
			}
		);

		return new ConnectionTestController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			probe: $probe,
			stufRegister: $register,
			logger: $logger,
		);
	}//end controllerFor()
}//end class
