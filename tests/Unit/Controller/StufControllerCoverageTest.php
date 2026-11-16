<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the StUF admin REST surface and the confirmation status mapping.
 *
 * Covers the outbound vrijBericht send (its required fields, an unknown
 * endpoint, integriq's answer and both failure codes), the audit-log query
 * (its clamped limit and the optional filters it passes on) and how the
 * inbound confirmation URL maps each integriq status onto the answer the
 * zaaksysteem gets.
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
use Psr\Log\LoggerInterface;

/**
 * The StUF admin endpoints and the confirmation status mapping.
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

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'endpointId and berichtNaam are required'], $response->getData());
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

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'Endpoint not found'], $response->getData());
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

		self::assertSame(200, $response->getStatus());
		self::assertSame(['status' => 'sent', 'messageId' => 'm-1'], $response->getData());
	}//end testOutboundSendsThroughIntegriq()

	/**
	 * Integriq refusing the message is a 400 with STUF_VALIDATION.
	 *
	 * @return void
	 */
	public function testOutboundRefusedByIntegriqIsA400(): void {
		$this->params = ['endpointId' => 'ep-1', 'berichtNaam' => 'ping'];
		$this->register->method('findById')->willReturn(['id' => 'ep-1']);
		$this->bridge->method('vrijBericht')->willThrowException(new StufException('No integriq source', 0));

		$response = $this->controller()->outbound();

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'No integriq source', 'errorCode' => 'STUF_VALIDATION'], $response->getData());
	}//end testOutboundRefusedByIntegriqIsA400()

	/**
	 * Integriq not answering is a 503 with INTEGRIQ_UNAVAILABLE.
	 *
	 * @return void
	 */
	public function testOutboundWithIntegriqAwayIsA503(): void {
		$this->params = ['endpointId' => 'ep-1', 'berichtNaam' => 'ping'];
		$this->register->method('findById')->willReturn(['id' => 'ep-1']);
		$this->bridge->method('vrijBericht')->willThrowException(new StufException('Integriq is unavailable', 503));

		$response = $this->controller()->outbound();

		self::assertSame(503, $response->getStatus());
		self::assertSame(['error' => 'Integriq is unavailable', 'errorCode' => 'INTEGRIQ_UNAVAILABLE'], $response->getData());
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

		self::assertSame(200, $response->getStatus());
		self::assertSame(['items' => $rows, 'total' => 2, 'limit' => 500], $response->getData());
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

		self::assertSame([['stufMessage', [], 50], ['stufMessage', [], 1]], $seen);
		self::assertSame(['items' => [], 'total' => 0, 'limit' => 50], $first->getData());
		self::assertSame(['items' => [], 'total' => 0, 'limit' => 1], $second->getData());
	}//end testMessagesDefaultsAndLowerBound()

	/**
	 * Each integriq status maps onto the zaaksysteem's answer; an unknown one is a bad gateway.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function confirmationStatuses(): array {
		return [
			'bad request' => [400, 400],
			'unavailable' => [503, 503],
			'unexpected' => [418, 502],
		];
	}//end confirmationStatuses()

	/**
	 * The inbound confirmation answer carries the mapped status and integriq's body as XML.
	 *
	 * @param int $integriq The status integriq answered with.
	 * @param int $expected The status the zaaksysteem gets.
	 *
	 * @dataProvider confirmationStatuses
	 *
	 * @return void
	 */
	public function testInboundMapsIntegriqsStatus(int $integriq, int $expected): void {
		$this->bridge->expects(self::once())->method('forwardConfirmation')
			->willReturn(['statusCode' => $integriq, 'body' => '<Fo03/>']);

		$response = $this->controller()->inbound();

		self::assertSame($expected, $response->getStatus());
		self::assertSame('<Fo03/>', $response->render());
		self::assertSame('text/xml; charset=utf-8', $response->getHeaders()['Content-Type']);
	}//end testInboundMapsIntegriqsStatus()

	/**
	 * Without a request body (the CLI's php://input) an empty body is forwarded.
	 *
	 * @return void
	 */
	public function testTheRealBodyReaderForwardsAnEmptyBody(): void {
		$this->bridge->expects(self::once())->method('forwardConfirmation')->with('')
			->willReturn(['statusCode' => 200, 'body' => '<Bv03/>']);

		$controller = new StufController(
			$this->request,
			$this->register,
			$this->bridge,
			$this->l10n(),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->createMock(originalClassName: IntegrationStatusServiceInterface::class),
		);

		self::assertSame(200, $controller->inbound()->getStatus());
	}//end testTheRealBodyReaderForwardsAnEmptyBody()

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
	 * The controller over the doubles, reading a fixed body.
	 *
	 * @return StufController The controller.
	 */
	private function controller(): StufController {
		return new class(
			$this->request,
			$this->register,
			$this->bridge,
			$this->l10n(),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->createMock(originalClassName: IntegrationStatusServiceInterface::class),
		) extends StufController {
			/**
			 * Serve a fixed body instead of php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return '<Bv01/>';
			}
		};
	}//end controller()
}//end class
