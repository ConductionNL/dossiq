<?php

/**
 * WorkflowDefinitionController unit tests: deprecate, clone and the two read-only consumer endpoints.
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
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WorkflowDefinitionController;
use OCA\Dossiq\Kernel\Contracts\WorkflowDefinitionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WorkflowLifecycleGuardInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every endpoint besides publish: the service's answer, or its null as a refusal.
 *
 * @covers \OCA\Dossiq\Controller\WorkflowDefinitionController
 */
class WorkflowDefinitionControllerLifecycleTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var WorkflowDefinitionServiceInterface&MockObject
	 */
	private WorkflowDefinitionServiceInterface $service;

	/**
	 * The controller under test.
	 *
	 * @var WorkflowDefinitionController
	 */
	private WorkflowDefinitionController $controller;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(WorkflowDefinitionServiceInterface::class);
		$this->controller = new WorkflowDefinitionController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			guard: $this->createMock(WorkflowLifecycleGuardInterface::class),
		);
	}//end setUp()

	/**
	 * A deprecated definition comes back.
	 *
	 * @return void
	 */
	public function testDeprecateReturnsTheDefinition(): void {
		$this->service->expects($this->once())->method('deprecate')->with('wf-1')
			->willReturn(['id' => 'wf-1', 'status' => 'deprecated']);

		$response = $this->controller->deprecate(id: 'wf-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'definition' => ['id' => 'wf-1', 'status' => 'deprecated']], $response->getData());
	}//end testDeprecateReturnsTheDefinition()

	/**
	 * A deprecation the service could not do is a 400.
	 *
	 * @return void
	 */
	public function testDeprecateThatFailedIsABadRequest(): void {
		$this->service->method('deprecate')->willReturn(null);

		$response = $this->controller->deprecate(id: 'wf-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Could not deprecate workflow definition'], $response->getData());
	}//end testDeprecateThatFailedIsABadRequest()

	/**
	 * A clone comes back as the new definition.
	 *
	 * @return void
	 */
	public function testCloneReturnsTheNewDefinition(): void {
		$this->service->expects($this->once())->method('cloneDefinition')->with('wf-1')
			->willReturn(['id' => 'wf-2', 'status' => 'draft']);

		$response = $this->controller->cloneDefinition(id: 'wf-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true, 'definition' => ['id' => 'wf-2', 'status' => 'draft']], $response->getData());
	}//end testCloneReturnsTheNewDefinition()

	/**
	 * A clone the service could not make is a 400.
	 *
	 * @return void
	 */
	public function testCloneThatFailedIsABadRequest(): void {
		$this->service->method('cloneDefinition')->willReturn(null);

		$response = $this->controller->cloneDefinition(id: 'wf-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['success' => false, 'error' => 'Could not clone workflow definition'], $response->getData());
	}//end testCloneThatFailedIsABadRequest()

	/**
	 * The active definition of a case type, or a 404 when there is none.
	 *
	 * @return void
	 */
	public function testActiveAnswersTheDefinitionOrNotFound(): void {
		$this->service->method('getActiveDefinitionFor')->willReturnMap([
			['ct-1', null, ['id' => 'wf-1']],
			['ct-2', null, null],
		]);

		$found = $this->controller->active(caseTypeId: 'ct-1');
		$missing = $this->controller->active(caseTypeId: 'ct-2');

		$this->assertSame(['success' => true, 'definition' => ['id' => 'wf-1']], $found->getData());
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['success' => false, 'error' => 'No active workflow definition'], $missing->getData());
	}//end testActiveAnswersTheDefinitionOrNotFound()

	/**
	 * The definition pinned to a case, or a 404 when there is none.
	 *
	 * @return void
	 */
	public function testForCaseAnswersThePinnedDefinitionOrNotFound(): void {
		$this->service->method('getDefinitionForCase')->willReturnMap([
			['case-1', ['id' => 'wf-1', 'version' => 3]],
			['case-2', null],
		]);

		$found = $this->controller->forCase(caseId: 'case-1');
		$missing = $this->controller->forCase(caseId: 'case-2');

		$this->assertSame(200, $found->getStatus());
		$this->assertSame(['success' => true, 'definition' => ['id' => 'wf-1', 'version' => 3]], $found->getData());
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['success' => false, 'error' => 'No workflow definition for case'], $missing->getData());
	}//end testForCaseAnswersThePinnedDefinitionOrNotFound()
}//end class
