<?php

/**
 * WorkflowDefinitionController::publish answers.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WorkflowDefinitionController;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every publish answer: published, a refused task declaration, an invalid step configuration, anything else.
 *
 * @covers \OCA\Dossiq\Controller\WorkflowDefinitionController
 */
class WorkflowDefinitionPublishTest extends TestCase {

	/**
	 * The controller over a service answering $result and a guard reporting the refusals.
	 *
	 * @param array<string, mixed>|null        $result     What publishing answers.
	 * @param array<int, array<string, mixed>> $refusals   The task declaration refusals.
	 * @param array<int, array<string, mixed>> $stepErrors The step configuration errors.
	 *
	 * @return WorkflowDefinitionController
	 */
	private function controller(?array $result, array $refusals, array $stepErrors): WorkflowDefinitionController {
		$service = $this->createMock(WorkflowDefinitionService::class);
		$service->method('publish')->willReturn($result);
		$guard = $this->createMock(WorkflowLifecycleGuard::class);
		$guard->method('lastRefusals')->willReturn($refusals);
		$guard->method('lastStepConfigErrors')->willReturn($stepErrors);

		return new WorkflowDefinitionController($this->createMock(IRequest::class), $service, $guard);
	}//end controller()

	/**
	 * Every outcome answers its status and body.
	 *
	 * @return void
	 */
	public function testEveryPublishOutcome(): void {
		$refusal = ['task' => 't-1', 'message' => 'Task t-1 names no role'];
		$stepError = ['path' => 'steps[0].config.sla', 'code' => 'missing', 'message' => 'internal'];
		$answers = [];
		foreach (
			[
				[['id' => 'wf-1', 'status' => 'published'], [], []],
				[null, [$refusal], [$stepError]],
				[null, [], [$stepError]],
				[null, [], []],
			] as [$result, $refusals, $stepErrors]
		) {
			$response = $this->controller(result: $result, refusals: $refusals, stepErrors: $stepErrors)->publish('wf-1');
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[200, ['success' => true, 'definition' => ['id' => 'wf-1', 'status' => 'published']]],
				[422, ['success' => false, 'error' => 'task_declaration_unresolvable', 'message' => 'Task t-1 names no role', 'errors' => [$refusal]]],
				[
					422,
					[
						'success' => false,
						'error' => 'step_config_invalid',
						'message' => 'A step configuration is not valid. Each entry in errors names the step and field, and what is wrong with it.',
						'errors' => [['path' => 'steps[0].config.sla', 'code' => 'missing']],
					],
				],
				[400, ['success' => false, 'error' => 'Could not publish workflow definition']],
			],
			$answers
		);
	}//end testEveryPublishOutcome()
}//end class
