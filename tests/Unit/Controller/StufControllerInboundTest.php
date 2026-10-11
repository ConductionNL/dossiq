<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the inbound StUF confirmation URL, now a forwarder to integriq.
 *
 * `StufController::inbound()` is a `#[PublicPage]` route and a wire contract
 * in the zaaksysteem's configuration. Since decision 193 integriq records the
 * confirmation and owns the WS-Security check (integriq's
 * StufZdsConfirmationReceiverTest watches it refuse a wrong password, an
 * unknown crossRefnummer and a source without credentials). What dossiq must
 * still get right: the bytes reach integriq unchanged, integriq's refusal is
 * passed on as a refusal, and integriq being away is a 503 so the sender
 * retries, never an acknowledgement.
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
use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\Stuf\StufIntegriqBridge;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\StufController
 */
class StufControllerInboundTest extends TestCase {

	/**
	 * The body integriq saw, or null when nothing was forwarded.
	 *
	 * @var string|null
	 */
	private ?string $forwarded = null;

	/**
	 * Integriq's answer is the zaaksysteem's answer, status and body.
	 *
	 * @return void
	 */
	public function testIntegriqsAnswerIsPassedOn(): void {
		$response = $this->controller(answer: ['statusCode' => 200, 'body' => '<Bv03/>'])->inbound();

		$this->assertSame('<Bv01 raw="1"/>', $this->forwarded, 'The bytes reach integriq unchanged');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('<Bv03/>', $response->render());
	}//end testIntegriqsAnswerIsPassedOn()

	/**
	 * Integriq's refusal stays a refusal.
	 *
	 * @return void
	 */
	public function testIntegriqsRefusalStaysARefusal(): void {
		$response = $this->controller(answer: ['statusCode' => 422, 'body' => '<Fo03/>'])->inbound();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('<Fo03/>', $response->render());
	}//end testIntegriqsRefusalStaysARefusal()

	/**
	 * Integriq away is a 503, so the zaaksysteem retries.
	 *
	 * @return void
	 */
	public function testIntegriqAwayIsA503(): void {
		$response = $this->controller(answer: null)->inbound();

		$this->assertSame(503, $response->getStatus());
	}//end testIntegriqAwayIsA503()

	/**
	 * The deprecated /api/stuf/inkomend forwards the same way.
	 *
	 * @return void
	 */
	public function testTheLegacyPathForwardsToo(): void {
		$response = $this->controller(answer: ['statusCode' => 200, 'body' => '<Bv03/>'])->inboundLegacyPath();

		$this->assertSame('<Bv01 raw="1"/>', $this->forwarded);
		$this->assertSame(200, $response->getStatus());
	}//end testTheLegacyPathForwardsToo()

	/**
	 * Build the controller over a bridge that plays integriq.
	 *
	 * @param array{statusCode:int,body:string}|null $answer Integriq's answer, null for none.
	 *
	 * @return StufController The controller, reading a fixed body.
	 */
	private function controller(?array $answer): StufController {
		$this->forwarded = null;
		$bridge = $this->createMock(StufIntegriqBridge::class);
		$bridge->method('forwardConfirmation')->willReturnCallback(
			function (string $rawXml) use ($answer): ?array {
				$this->forwarded = $rawXml;
				return $answer;
			}
		);

		return new class('dossiq', $this->createMock(IRequest::class), $this->createMock(StufRegisterAccess::class), $bridge, $this->createMock(StufSoapRequestDispatcher::class), $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class), $this->createMock(IntegrationStatusService::class)) extends StufController {
			/**
			 * Serve a fixed body instead of php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return '<Bv01 raw="1"/>';
			}
		};
	}//end controller()
}//end class
