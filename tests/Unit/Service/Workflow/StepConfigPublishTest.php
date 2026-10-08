<?php

/**
 * A step configuration that does not hold is refused at publish.
 *
 * `StepConfigValidator` shipped with its seven rules and nothing asked it
 * (dossiq#3154): the workflow editor wrote SLA and escalation blocks onto the
 * steps of a draft, the publish endpoint flipped the draft live, and an SLA in
 * `weeks` or an escalation rule with no SLA to escalate from went straight into
 * the published template. The design of process-step-configuration puts the
 * check on publish and NOT on draft save: a draft may hold a half-typed config,
 * a published template may not.
 *
 * These tests go through the caller, `WorkflowDefinitionService::publish()` and
 * the controller behind `POST /api/workflow-definitions/{id}/publish`, with the
 * real lifecycle guard. A test of the validator alone would stay green while
 * nothing called it, which is exactly how the capability was dark.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Controller\WorkflowDefinitionController;
use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Publishing refuses a step whose config breaks the validator's rules.
 *
 * @covers \OCA\Dossiq\Service\WorkflowDefinitionService
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 * @covers \OCA\Dossiq\Controller\WorkflowDefinitionController
 * @uses \OCA\Dossiq\Service\StepConfigValidator
 * @uses \OCA\Dossiq\Service\StepConfig\EscalationRuleValidator
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 * @uses \OCA\Dossiq\Service\Workflow\StepConfigCheck
 */
class StepConfigPublishTest extends TestCase {

	/**
	 * The definition rows the repository answers with, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every save the repository received, as [uuid, payload].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * The guard the service and the controller share, as the DI container shares it.
	 *
	 * @var WorkflowLifecycleGuard
	 */
	private WorkflowLifecycleGuard $guard;

	/**
	 * The service under test.
	 *
	 * @var WorkflowDefinitionService
	 */
	private WorkflowDefinitionService $service;

	/**
	 * Wire the real service and guard over a recording repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$repository = $this->createMock(WorkflowDefinitionRepository::class);
		$repository->method('findById')->willReturnCallback(
			fn (string $id): ?array => ($this->rows[$id] ?? null)
		);
		$repository->method('findCaseType')->willReturn(['id' => 'ct-bezwaar']);
		$repository->method('listVersionsForCaseType')->willReturn([]);
		$repository->method('listStatusTypeIds')->willReturn([]);
		$repository->method('isConfiguredFor')->willReturn(true);
		$repository->method('save')->willReturnCallback(
			function (array $payload, ?string $uuid = null): array {
				$uuid = ($uuid ?? 'new-row');
				$this->saves[] = [$uuid, $payload];
				$this->rows[$uuid] = array_merge(($this->rows[$uuid] ?? ['id' => $uuid]), $payload);
				return $this->rows[$uuid];
			}
		);

		$stamper = $this->createMock(TransitionAuthorizationStamper::class);
		$stamper->method('stamp')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);

		$this->guard = new WorkflowLifecycleGuard($repository, $logger);
		$this->service = new WorkflowDefinitionService(
			$repository,
			$this->guard,
			$stamper,
			new WorkflowJsonProperty(),
			$logger
		);
	}//end setUp()

	/**
	 * A draft definition whose `steps` is the JSON string the schema stores.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 *
	 * @return void
	 */
	private function draftWithSteps(array $steps): void {
		$this->rows['wf-1'] = [
			'id' => 'wf-1',
			'title' => 'Bezwaar',
			'caseType' => 'ct-bezwaar',
			'lifecycleStatus' => WorkflowDefinitionService::STATUS_DRAFT,
			'isActive' => false,
			'version' => 1,
			'transitions' => '[]',
			'steps' => json_encode($steps),
		];
	}//end draftWithSteps()

	/**
	 * Whether anything was written as published.
	 *
	 * @return boolean True when a save flipped a row to published.
	 */
	private function somethingWasPublished(): bool {
		foreach ($this->saves as [, $payload]) {
			if (($payload['lifecycleStatus'] ?? '') === WorkflowDefinitionService::STATUS_PUBLISHED) {
				return true;
			}
		}

		return false;
	}//end somethingWasPublished()

	/**
	 * An SLA in a unit the engine cannot count is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownSlaUnitRefusesPublicationAndWritesNothing(): void {
		$this->draftWithSteps(
			[
				['id' => 's1', 'title' => 'Toets termijn', 'config' => ['sla' => ['value' => 5, 'unit' => 'weeks']]],
			]
		);

		$this->assertNull($this->service->publish('wf-1'));
		$this->assertSame([], $this->saves, 'A refused publish must not write a single row.');

		$errors = $this->guard->lastStepConfigErrors();
		$this->assertCount(1, $errors);
		$this->assertSame('steps[0].config.sla.unit', $errors[0]['path']);
		$this->assertSame('unknown_sla_unit', $errors[0]['code']);
	}//end testAnUnknownSlaUnitRefusesPublicationAndWritesNothing()

	/**
	 * An escalation rule with no SLA to escalate from is refused on its own step.
	 *
	 * The path keeps the step's position in the template, so an administrator
	 * is sent to the second step and not to the first.
	 *
	 * @return void
	 */
	public function testAnEscalationRuleWithoutAnSlaIsRefusedOnItsOwnStep(): void {
		$this->draftWithSteps(
			[
				['id' => 's1', 'title' => 'Ontvangst', 'config' => ['sla' => ['value' => 2, 'unit' => 'businessDays']]],
				[
					'id' => 's2',
					'title' => 'Hoorzitting',
					'config' => [
						'escalationRule' => [
							'trigger' => 'slaBreached',
							'offset' => 0,
							'offsetUnit' => 'businessDays',
						],
					],
				],
			]
		);

		$this->assertNull($this->service->publish('wf-1'));
		$this->assertFalse($this->somethingWasPublished());

		$codes = array_column($this->guard->lastStepConfigErrors(), 'code', 'path');
		$this->assertSame(['steps[1].config.escalationRule' => 'escalation_requires_sla'], $codes);
	}//end testAnEscalationRuleWithoutAnSlaIsRefusedOnItsOwnStep()

	/**
	 * A step config that holds publishes as it did before.
	 *
	 * @return void
	 */
	public function testAValidStepConfigPublishes(): void {
		$this->draftWithSteps(
			[
				[
					'id' => 's1',
					'title' => 'Toets termijn',
					'config' => [
						'sla' => ['value' => 5, 'unit' => 'businessDays'],
						'requiredFields' => ['isTimely'],
						'escalationRule' => [
							'trigger' => 'preBreach',
							'offset' => 1,
							'offsetUnit' => 'businessDays',
							'notifyRole' => 'Behandelaar',
							'escalateToRole' => 'Afdelingshoofd',
							'openIncident' => false,
						],
					],
				],
				['id' => 's2', 'title' => 'Zonder configuratie'],
			]
		);

		$this->assertNotNull($this->service->publish('wf-1'));
		$this->assertTrue($this->somethingWasPublished());
		$this->assertSame([], $this->guard->lastStepConfigErrors());
	}//end testAValidStepConfigPublishes()

	/**
	 * A draft may still be saved with a half-typed config; only publish refuses.
	 *
	 * @return void
	 */
	public function testADraftSaveIsNotRefused(): void {
		$draft = $this->service->createDraft(
			[
				'caseType' => 'ct-bezwaar',
				'title' => 'Bezwaar',
				'steps' => [['id' => 's1', 'config' => ['sla' => ['value' => 0, 'unit' => 'weeks']]]],
			]
		);

		$this->assertNotNull($draft);
	}//end testADraftSaveIsNotRefused()

	/**
	 * The endpoint answers 422 with where and what, and never the internal message.
	 *
	 * The validator's `message` is for the log. The spec says callers must not
	 * surface it, so the answer carries each error's path and code, which is
	 * what the editor needs to point at the field.
	 *
	 * @return void
	 */
	public function testThePublishEndpointAnswers422WithPathAndCode(): void {
		$this->draftWithSteps(
			[
				['id' => 's1', 'title' => 'Toets termijn', 'config' => ['sla' => ['value' => 99999, 'unit' => 'hours']]],
			]
		);

		$controller = new WorkflowDefinitionController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->guard
		);

		$response = $controller->publish('wf-1');
		$data = $response->getData();

		$this->assertSame(422, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('step_config_invalid', $data['error']);
		$this->assertSame([['path' => 'steps[0].config.sla.value', 'code' => 'out_of_range']], $data['errors']);
		$this->assertFalse($this->somethingWasPublished());
	}//end testThePublishEndpointAnswers422WithPathAndCode()
}//end class
