<?php

/**
 * DSOIntakeController Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DSOIntakeController;
use OCA\Dossiq\Service\DsoIntakeService;
use OCP\IAppConfig;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DSOIntakeController.
 *
 * @covers \OCA\Dossiq\Controller\DSOIntakeController
 */
class DSOIntakeControllerTest extends TestCase {

	/**
	 * @var DsoIntakeService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private DsoIntakeService $dsoIntakeService;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var DSOIntakeController
	 */
	private DSOIntakeController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dsoIntakeService = $this->createMock(DsoIntakeService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: no DSO webhook secret configured, so signature validation
		// is skipped (returns the supplied default unchanged).
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $default);

		$this->controller = new DSOIntakeController(
			appName: 'dossiq',
			request: $this->request,
			dsoIntakeService: $this->dsoIntakeService,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that the DSOIntakeController can be instantiated with expected dependencies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function testControllerCanBeInstantiated(): void {
		$this->assertInstanceOf(DSOIntakeController::class, $this->controller);
	}//end testControllerCanBeInstantiated()

	/**
	 * Test that the controller uses a public endpoint attribute.
	 *
	 * Verifies that the class has a PublicPage attribute or @PublicPage annotation
	 * on the intake method, confirming it is accessible without authentication.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function testIntakeMethodIsPublicPage(): void {
		$reflection = new \ReflectionMethod(objectOrMethod: DSOIntakeController::class, method: 'intake');
		$attributes = $reflection->getAttributes();

		$attributeNames = array_map(
			static fn ($attr) => $attr->getName(),
			$attributes
		);

		$this->assertContains(
			needle: 'OCP\AppFramework\Http\Attribute\PublicPage',
			haystack: $attributeNames,
			message: 'intake() must have #[PublicPage] attribute'
		);
	}//end testIntakeMethodIsPublicPage()

	/**
	 * Test that the controller uses NoCSRFRequired on intake.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function testIntakeMethodIsNoCSRFRequired(): void {
		$reflection = new \ReflectionMethod(objectOrMethod: DSOIntakeController::class, method: 'intake');
		$attributes = $reflection->getAttributes();

		$attributeNames = array_map(
			static fn ($attr) => $attr->getName(),
			$attributes
		);

		$this->assertContains(
			needle: 'OCP\AppFramework\Http\Attribute\NoCSRFRequired',
			haystack: $attributeNames,
			message: 'intake() must have #[NoCSRFRequired] attribute'
		);
	}//end testIntakeMethodIsNoCSRFRequired()

	/**
	 * Test that the intake method is callable and returns a JSONResponse.
	 *
	 * Uses a request mock where the header method returns empty for signature check.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-3
	 */
	public function testIntakeReturnsJsonResponse(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParams')->willReturn(['activiteiten' => [], 'procedureType' => 'regulier']);
		$this->dsoIntakeService->method('processAanvraag')->willReturn(['caseId' => 'case-1']);

		$response = $this->controller->intake();

		$this->assertInstanceOf(
			expected: \OCP\AppFramework\Http\JSONResponse::class,
			actual: $response
		);

		// Alone, assertInstanceOf passes on the 400 and the 500 this method can
		// also answer, so the accepted intake states its own status here
		// (refusals-carry-a-status, REQ-QG-CRN-2).
		$this->assertSame(
			expected: Http::STATUS_CREATED,
			actual: $response->getStatus(),
			message: 'An accepted intake answers 201 with the case it made.'
		);
	}//end testIntakeReturnsJsonResponse()

	/**
	 * An unsigned payload is refused with 400 and a sentence, not accepted.
	 *
	 * The pair to the test above: the same endpoint, the same shape of
	 * assertion, on the branch where the webhook's signature rule says no.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnsignedIntakeIsRefusedWith400(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('a-configured-secret');

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParams')->willReturn(['activiteiten' => []]);

		$service = $this->createMock(originalClassName: DsoIntakeService::class);
		$service->expects($this->never())->method('processAanvraag');

		$controller = new DSOIntakeController(
			appName: 'dossiq',
			request: $request,
			dsoIntakeService: $service,
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$response = $controller->intake();

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'An unsigned DSO payload is refused, and the refusal carries its status.'
		);
		$this->assertSame(
			expected: 'Invalid or missing DSO signature',
			actual: ((array)$response->getData())['message']
		);
	}//end testAnUnsignedIntakeIsRefusedWith400()
}//end class
