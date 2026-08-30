<?php

/**
 * DwangsomPaymentCallbackController signature-enforcement tests.
 *
 * Verifies the callback fails closed (401) when no secret is configured,
 * rejects an incorrectly signed request, and accepts a correctly signed
 * request once a secret is configured — closing the fail-open gap
 * described in enforce-dwangsom-callback-signature.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforce-dwangsom-callback-signature/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DwangsomPaymentCallbackController;
use OCA\Dossiq\Service\DwangsomUitbetalingService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Signature-enforcement tests for DwangsomPaymentCallbackController.
 *
 * @covers \OCA\Dossiq\Controller\DwangsomPaymentCallbackController
 */
class DwangsomPaymentCallbackControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked uitbetaling service.
	 *
	 * @var DwangsomUitbetalingService|MockObject
	 */
	private DwangsomUitbetalingService $service;

	/**
	 * The mocked app config.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: DwangsomUitbetalingService::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller under test with the shared mocks.
	 *
	 * @return DwangsomPaymentCallbackController
	 */
	private function makeController(): DwangsomPaymentCallbackController {
		return new DwangsomPaymentCallbackController(
			appName: 'dossiq',
			request: $this->request,
			service: $this->service,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end makeController()

	/**
	 * An unconfigured secret MUST fail closed (401), never an implicit pass.
	 *
	 * @return void
	 */
	public function testCallbackRejectsWhenSecretUnconfigured(): void {
		$this->appConfig->method('getValueString')
			->with('dossiq', 'dwangsom_callback_secret', '')
			->willReturn('');

		$this->logger->expects($this->atLeastOnce())->method('warning');
		$this->service->expects($this->never())->method('handleCallback');

		$response = $this->makeController()->callback();

		$this->assertSame(expected: 401, actual: $response->getStatus());
	}//end testCallbackRejectsWhenSecretUnconfigured()

	/**
	 * A signature that does not match the configured secret is rejected.
	 *
	 * @return void
	 */
	public function testCallbackRejectsInvalidSignature(): void {
		$this->appConfig->method('getValueString')
			->with('dossiq', 'dwangsom_callback_secret', '')
			->willReturn('super-secret');

		$this->request->method('getHeader')
			->with('X-Procest-Signature')
			->willReturn('not-a-valid-signature');

		$this->service->expects($this->never())->method('handleCallback');

		$response = $this->makeController()->callback();

		$this->assertSame(expected: 401, actual: $response->getStatus());
	}//end testCallbackRejectsInvalidSignature()

	/**
	 * A correctly signed body, once a secret is configured, passes the
	 * signature validator (`validateSignature()` is private; exercised via
	 * reflection since `callback()` itself reads `php://input` directly and
	 * cannot be driven from a unit test without a stream-wrapper override).
	 *
	 * @return void
	 */
	public function testValidateSignatureAcceptsCorrectHmac(): void {
		$secret = 'super-secret';
		$rawBody = json_encode(['reference' => 'REF-123', 'status' => 'paid']);

		$this->appConfig->method('getValueString')
			->with('dossiq', 'dwangsom_callback_secret', '')
			->willReturn($secret);

		$this->request->method('getHeader')
			->with('X-Procest-Signature')
			->willReturn(hash_hmac('sha256', $rawBody, $secret));

		$method = new \ReflectionMethod(DwangsomPaymentCallbackController::class, 'validateSignature');
		$method->setAccessible(true);

		$this->assertTrue(condition: $method->invoke($this->makeController(), $rawBody));
	}//end testValidateSignatureAcceptsCorrectHmac()
}//end class
