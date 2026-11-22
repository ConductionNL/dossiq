<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the StUF admin REST surface.
 *
 * Covers the outbound vrijBericht send (its required fields, an unknown
 * endpoint, integriq's answer and both failure codes), the audit-log query
 * (its clamped limit and the optional filters it passes on), and that the
 * forwarding URLs a zaaksysteem used before integriq took the wire are gone.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Kernel\Contracts\IntegrationStatusServiceInterface;
use OCA\Dossiq\Kernel\Contracts\StufIntegriqBridgeInterface;
use OCA\Dossiq\Kernel\Contracts\StufRegisterAccessInterface;
use OCA\Dossiq\Service\Stuf\StufException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The StUF admin endpoints.
 *
 * @covers \OCA\Dossiq\Controller\StufController
 */
class StufControllerCoverageTest extends TestCase {

	/**
	 * The request parameters the request double answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The register double.
	 *
	 * @var StufRegisterAccessInterface&MockObject
	 */
	private StufRegisterAccessInterface $register;

	/**
	 * The integriq bridge double.
	 *
	 * @var StufIntegriqBridgeInterface&MockObject
	 */
	private StufIntegriqBridgeInterface $bridge;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default
		);
		$this->register = $this->createMock(originalClassName: StufRegisterAccessInterface::class);
		$this->bridge = $this->createMock(originalClassName: StufIntegriqBridgeInterface::class);
	}//end setUp()

	/**
	 * Without endpointId or berichtNaam the send is a 400 and nothing is looked up or sent.
	 *
	 * @return void
	 */
	public function testOutboundWithoutTheRequiredFieldsIsA400(): void {
		$this->params = ['endpointId' => 'ep-1'];
		$this->register->expects(self::never())->method('findById');
		$this->bridge->expects(self::never())->method('vrijBericht');

		$response = $this->controller()->outbound();

		self::assertSame(expected: 400, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'endpointId and berichtNaam are required'], actual: $response->getData());
	}//end testOutboundWithoutTheRequiredFieldsIsA400()

	/**
	 * An unknown endpoint is a 404 and nothing is sent.
	 *
	 * @return void
	 */
	public function testOutboundToAnUnknownEndpointIsA404(): void {
		$this->params = ['endpointId' => 'ep-9', 'berichtNaam' => 'ping'];
		$this->register->expects(self::once())->method('findById')->with('stufEndpoint', 'ep-9')->willReturn(null);
		$this->bridge->expects(self::never())->method('vrijBericht');

		$response = $this->controller()->outbound();

		self::assertSame(expected: 404, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'Endpoint not found'], actual: $response->getData());
	}//end testOutboundToAnUnknownEndpointIsA404()

	/**
	 * A known endpoint sends the message through integriq and answers with integriq's result.
	 *
	 * @return void
	 */
	public function testOutboundSendsThroughIntegriq(): void {
		$endpoint = ['id' => 'ep-1', 'integriqSource' => 'src-1'];
		$this->params = ['endpointId' => 'ep-1', 'berichtNaam' => 'ping', 'payload' => ['a' => 'b']];
		$this->register->method('findById')->willReturn($endpoint);
		$this->bridge->expects(self::once())->method('vrijBericht')
			->with($endpoint, 'ping', ['a' => 'b'])
			->willReturn(['status' => 'sent', 'messageId' => 'm-1']);

		$response = $this->controller()->outbound();

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: ['status' => 'sent', 'messageId' => 'm-1'], actual: $response->getData());
	}//end testOutboundSendsThroughIntegriq()

	/**
	 * Integriq refusing the message is a 400 with STUF_VALIDATION.
	 *
	 * @return void
	 */
	public function testOutboundRefusedByIntegriqIsA400(): void {
		$this->params = ['endpointId' => 'ep-1', 'berichtNaam' => 'ping'];
		$this->register->method('findById')->willReturn(['id' => 'ep-1']);
		$this->bridge->method('vrijBericht')->willThrowException(new StufException(message: 'No integriq source', code: 0));

		$response = $this->controller()->outbound();

		self::assertSame(expected: 400, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'No integriq source', 'errorCode' => 'STUF_VALIDATION'], actual: $response->getData());
	}//end testOutboundRefusedByIntegriqIsA400()

	/**
	 * Integriq not answering is a 503 with INTEGRIQ_UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testOutboundWithIntegriqAwayIsA503(): void {
		$this->params = ['endpointId' => 'ep-1', 'berichtNaam' => 'ping'];
		$this->register->method('findById')->willReturn(['id' => 'ep-1']);
		$this->bridge->method('vrijBericht')->willThrowException(new StufException(message: 'Integriq is unavailable', code: 503));

		$response = $this->controller()->outbound();

		self::assertSame(expected: 503, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'Integriq is unavailable', 'errorCode' => 'INTEGRIQ_UNAVAILABLE'], actual: $response->getData());
	}//end testOutboundWithIntegriqAwayIsA503()

	/**
	 * The audit log passes only the non-empty filters on and clamps the limit to 500.
	 *
	 * @return void
	 */
	public function testMessagesPassesTheFiltersAndClampsTheLimit(): void {
		$this->params = ['limit' => '9999', 'endpointId' => 'ep-1', 'messageKind' => '', 'status' => 'failed'];
		$rows = [['id' => 'm-1'], ['id' => 'm-2']];
		$this->register->expects(self::once())->method('findAll')
			->with('stufMessage', ['endpointId' => 'ep-1', 'status' => 'failed'], 500)
			->willReturn($rows);

		$response = $this->controller()->messages();

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: ['items' => $rows, 'total' => 2, 'limit' => 500], actual: $response->getData());
	}//end testMessagesPassesTheFiltersAndClampsTheLimit()

	/**
	 * Without parameters the audit log reads 50 rows unfiltered; a limit below one becomes one.
	 *
	 * @return void
	 */
	public function testMessagesDefaultsAndLowerBound(): void {
		$seen = [];
		$this->register->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters, int $limit) use (&$seen): array {
				$seen[] = [$schema, $filters, $limit];
				return [];
			}
		);

		$first = $this->controller()->messages();
		$this->params = ['limit' => '-3'];
		$second = $this->controller()->messages();

		self::assertSame(expected: [['stufMessage', [], 50], ['stufMessage', [], 1]], actual: $seen);
		self::assertSame(expected: ['items' => [], 'total' => 0, 'limit' => 50], actual: $first->getData());
		self::assertSame(expected: ['items' => [], 'total' => 0, 'limit' => 1], actual: $second->getData());
	}//end testMessagesDefaultsAndLowerBound()

	/**
	 * The confirmation and provider forwarders are gone: no route and no action serves them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-confirmations-reach-integriq-on-its-own-url
	 * @spec openspec/specs/stuf-integration/spec.md#requirement-provider-requests-reach-integriq-on-its-own-urls
	 */
	public function testTheForwardingUrlsAreGone(): void {
		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		foreach (['inbound', 'inkomend', 'cases', 'persons', 'zaken', 'personen'] as $path) {
			self::assertStringNotContainsString("'/api/stuf/" . $path . "'", $routes, 'The forwarder /api/stuf/' . $path . ' must be gone.');
		}

		foreach (['inbound', 'inboundLegacyPath', 'cases', 'casesLegacyPath', 'persons', 'personsLegacyPath'] as $method) {
			self::assertFalse(method_exists(StufController::class, $method), 'StufController::' . $method . ' must be gone.');
		}

		self::assertStringContainsString("'/api/stuf/outbound'", $routes, 'The admin send stays.');
	}//end testTheForwardingUrlsAreGone()

	/**
	 * An IL10N double that returns the source string.
	 *
	 * @return IL10N&MockObject
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}//end l10n()

	/**
	 * The controller over the doubles.
	 *
	 * @return StufController The controller.
	 */
	private function controller(): StufController {
		return new StufController(
			request: $this->request,
			register: $this->register,
			bridge: $this->bridge,
			l10n: $this->l10n(),
			integrationStatus: $this->createMock(originalClassName: IntegrationStatusServiceInterface::class),
		);
	}//end controller()
}//end class
