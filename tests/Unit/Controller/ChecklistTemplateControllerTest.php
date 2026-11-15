<?php

/**
 * ChecklistTemplateController Unit Tests
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
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ChecklistTemplateController;
use OCA\Dossiq\Service\Checklist\ChecklistTemplateStore;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ChecklistTemplateController.
 *
 * @covers \OCA\Dossiq\Controller\ChecklistTemplateController
 */
class ChecklistTemplateControllerTest extends TestCase {

	/**
	 * @var ChecklistTemplateStore|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ChecklistTemplateStore $templates;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var ChecklistTemplateController
	 */
	private ChecklistTemplateController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->templates = $this->createMock(ChecklistTemplateStore::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ChecklistTemplateController(
			appName: 'dossiq',
			request: $this->request,
			templates: $this->templates,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that index returns 200 with checklist list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testIndexReturns200(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->templates
			->method('all')
			->willReturn([['id' => 'uuid-1', 'name' => 'Test Checklist']]);

		$response = $this->controller->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testIndexReturns200()

	/**
	 * Test that destroy returns 200 on successful deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testDestroyReturns200OnSuccess(): void {
		$this->templates
			->method('delete')
			->willReturn(true);

		$response = $this->controller->destroy(id: 'uuid-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testDestroyReturns200OnSuccess()

	/**
	 * Test that destroy returns 500 when deletion fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testDestroyReturns500OnFailure(): void {
		$this->templates
			->method('delete')
			->willReturn(false);

		$response = $this->controller->destroy(id: 'uuid-nonexistent');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
	}//end testDestroyReturns500OnFailure()

	/**
	 * The admin CRUD half of this controller carried the identical arm: a
	 * checklist whose payload the schema refuses came back as a 500, so an
	 * author who mistyped a field was told the server had broken.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testCreateAnswersFourHundredWhenOpenRegisterRefusesThePayload(): void {
		$this->request->method('getParams')->willReturn(['name' => '']);
		$this->templates
			->method('create')
			->willThrowException(
				new OpenRegisterValidationException(
					message: "Property 'caseTypeRef' should match format 'uuid' but 'vth' does not"
				)
			);

		$response = $this->controller->create();

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A refused checklist payload is the author\'s fault, not a server fault.'
		);
		$this->assertStringContainsString(
			needle: "Property 'caseTypeRef'",
			haystack: (string)($response->getData()['message'] ?? ''),
			message: 'The response must name the property that failed.'
		);
	}//end testCreateAnswersFourHundredWhenOpenRegisterRefusesThePayload()

	/**
	 * The same on update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testUpdateAnswersFourHundredWhenOpenRegisterRefusesThePayload(): void {
		$this->request->method('getParams')->willReturn(['name' => '']);
		$this->templates
			->method('update')
			->willThrowException(
				new OpenRegisterValidationException(
					message: "Property 'items' should be of type array but string given"
				)
			);

		$response = $this->controller->update(id: 'checklist-uuid');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A refused checklist update is the author\'s fault, not a server fault.'
		);
	}//end testUpdateAnswersFourHundredWhenOpenRegisterRefusesThePayload()

	/**
	 * And the other way on create: a genuine fault stays a 500.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testCreateStillAnswersFiveHundredForAGenuineServerFault(): void {
		$this->request->method('getParams')->willReturn(['name' => 'VTH']);
		$this->templates
			->method('create')
			->willThrowException(new \TypeError('Argument #2 must be of type array, null given'));

		$response = $this->controller->create();

		$this->assertSame(
			expected: Http::STATUS_INTERNAL_SERVER_ERROR,
			actual: $response->getStatus(),
			message: 'A code fault is the server\'s fault and must stay a 500.'
		);
	}//end testCreateStillAnswersFiveHundredForAGenuineServerFault()
}//end class
