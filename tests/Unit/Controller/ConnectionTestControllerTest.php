<?php

/**
 * ConnectionTestController wire-contract tests.
 *
 * Contract coverage for the one new network-facing endpoint (gate-25):
 * POST /api/connections/stuf/{endpointId}/test. Three realistic defects are
 * pinned: an unknown endpoint answering 200 with a made-up result, a configured
 * endpoint with no URL reading as untested rather than failed, and the probe
 * being handed a URL off the request instead of out of the store.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ConnectionTestController;
use OCA\Dossiq\Service\Starter\ConnectionTestService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wire-contract tests for ConnectionTestController::testStufEndpoint().
 *
 * @covers \OCA\Dossiq\Controller\ConnectionTestController
 */
class ConnectionTestControllerTest extends TestCase {

	/**
	 * The live probe, mocked so no test makes a network call.
	 *
	 * @var ConnectionTestService|MockObject
	 */
	private ConnectionTestService $probe;

	/**
	 * The endpoint store.
	 *
	 * @var StufRegisterAccess|MockObject
	 */
	private StufRegisterAccess $register;

	/**
	 * The controller under test.
	 *
	 * @var ConnectionTestController
	 */
	private ConnectionTestController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->probe = $this->getMockBuilder(ConnectionTestService::class)
			->disableOriginalConstructor()
			->onlyMethods(['probe'])
			->getMock();

		$this->register = $this->getMockBuilder(StufRegisterAccess::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();

		$stuf = $this->getMockBuilder(StufServices::class)
			->disableOriginalConstructor()
			->getMock();
		// `register` is a promoted public readonly property on StufServices, so
		// the double carries it the same way the real object does.
		$reflection = new \ReflectionProperty(StufServices::class, 'register');
		$reflection->setValue($stuf, $this->register);

		$this->controller = new ConnectionTestController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			probe: $this->probe,
			stuf: $stuf,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A configured endpoint is probed, and the answer names the endpoint and
	 * the status.
	 *
	 * @return void
	 */
	public function testAConfiguredEndpointIsProbedAndTheResultNamesTheStatus(): void {
		$this->register->method('findAll')->willReturn(
			[['id' => 'ep-1', 'name' => 'Amersfoort', 'endpointUrl' => 'https://zaken.example.org/stuf']]
		);

		$this->probe->expects($this->once())
			->method('probe')
			->with('https://zaken.example.org/stuf')
			->willReturn(
				[
					'state' => ConnectionTestService::REACHABLE,
					'endpoint' => 'https://zaken.example.org/stuf',
					'status' => 200,
					'reason' => '',
					'measuredAt' => '2026-09-15T08:00:00+00:00',
					'responseTimeMs' => 42,
				]
			);

		$response = $this->controller->testStufEndpoint(endpointId: 'ep-1');
		$data = $response->getData();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: ConnectionTestService::REACHABLE, actual: $data['state']);
		self::assertSame(expected: 200, actual: $data['status']);
		self::assertSame(expected: '2026-09-15T08:00:00+00:00', actual: $data['measuredAt']);
	}//end testAConfiguredEndpointIsProbedAndTheResultNamesTheStatus()

	/**
	 * An endpoint nobody configured is a 404, and nothing is probed.
	 *
	 * 🔑 THE ENDPOINT IS RESOLVED FROM THE STORE, NEVER OFF THE REQUEST. A
	 * controller that probed whatever the caller named would be a server-side
	 * request forgery with an admin button on it.
	 *
	 * @return void
	 */
	public function testAnUnknownEndpointIsNotProbed(): void {
		$this->register->method('findAll')->willReturn([]);
		$this->probe->expects($this->never())->method('probe');

		$response = $this->controller->testStufEndpoint(endpointId: 'ep-nowhere');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnUnknownEndpointIsNotProbed()

	/**
	 * A saved endpoint with no URL reads as failed, never as untested.
	 *
	 * The administrator did save this connection and it cannot work; reporting
	 * "not tested" would hide a broken configuration behind a neutral word.
	 *
	 * @return void
	 */
	public function testASavedEndpointWithNoUrlReadsAsFailed(): void {
		$this->register->method('findAll')->willReturn([['id' => 'ep-2', 'name' => 'Halfweg']]);
		$this->probe->expects($this->never())->method('probe');

		$data = $this->controller->testStufEndpoint(endpointId: 'ep-2')->getData();

		self::assertSame(expected: ConnectionTestService::FAILED, actual: $data['state']);
		self::assertNotSame(expected: '', actual: $data['reason']);
		self::assertNotSame(expected: '', actual: $data['measuredAt']);
	}//end testASavedEndpointWithNoUrlReadsAsFailed()

	/**
	 * A store that throws is a 500 that says nothing about the stack.
	 *
	 * @return void
	 */
	public function testAStoreThatThrowsAnswersFiveHundred(): void {
		$this->register->method('findAll')->willThrowException(new \RuntimeException('no register'));

		$response = $this->controller->testStufEndpoint(endpointId: 'ep-1');

		self::assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		self::assertArrayNotHasKey(key: 'exception', array: $response->getData());
	}//end testAStoreThatThrowsAnswersFiveHundred()
}//end class
