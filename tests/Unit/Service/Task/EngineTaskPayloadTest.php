<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskPayload;
use PHPUnit\Framework\TestCase;

/**
 * What the engine is sent for one dossiq task.
 *
 * @covers \OCA\Dossiq\Service\Task\EngineTaskPayload
 * @uses   \OCA\Dossiq\Service\Task\EngineTaskGateway
 */
class EngineTaskPayloadTest extends TestCase {

	/**
	 * A register row is keyed by its frozen source key; a once-key wins over it.
	 *
	 * @return void
	 */
	public function testKeysByTheRegisterRowUnlessAOnceKeyIsGiven(): void {
		$payload = new EngineTaskPayload();

		$this->assertSame(
			expected: 'dossiq:caseTask:row-1',
			actual: $payload->build(task: ['uuid' => ' row-1 '], caseId: '')['key']
		);
		$this->assertSame(
			expected: EngineTaskGateway::sourceKey(registerTaskId: 'row-1'),
			actual: $payload->build(task: ['id' => 'row-1', 'uuid' => 'other'], caseId: '')['key']
		);
		$this->assertSame(
			expected: 'once-1',
			actual: $payload->build(task: ['id' => 'row-1', 'onceKey' => ' once-1 '], caseId: '')['key']
		);
		$this->assertArrayNotHasKey(key: 'key', array: $payload->build(task: ['id' => '  '], caseId: ''));
	}//end testKeysByTheRegisterRowUnlessAOnceKeyIsGiven()

	/**
	 * The case's team is the candidate group unless the case type declared candidates.
	 *
	 * @return void
	 */
	public function testADeclaredCandidateListWinsOverTheCaseTeam(): void {
		$payload = new EngineTaskPayload();

		$this->assertSame(
			expected: ['team-a'],
			actual: $payload->build(task: ['assigneeGroup' => ' team-a '], caseId: 'c')['candidateGroups']
		);
		$this->assertSame(
			expected: ['team-b'],
			actual: $payload->build(task: ['assigneeGroup' => 'team-a', 'candidateGroups' => ['x' => 'team-b']], caseId: 'c')['candidateGroups']
		);
		$this->assertSame(
			expected: ['team-a'],
			actual: $payload->build(task: ['assigneeGroup' => 'team-a', 'candidateGroups' => []], caseId: 'c')['candidateGroups']
		);
	}//end testADeclaredCandidateListWinsOverTheCaseTeam()

	/**
	 * Copied fields are trimmed and renamed; blank ones and the completed date stay out.
	 *
	 * @return void
	 */
	public function testCopiesTrimmedFieldsUnderTheEngineNames(): void {
		$built = (new EngineTaskPayload())->build(
			task: [
				'title'         => 'T',
				'flowRun'       => ' run-1 ',
				'workflowStepId' => 'step-2',
				'assignee'      => '   ',
				'completedDate' => '2026-01-01',
			],
			caseId: 'case-1'
		);

		$this->assertSame(
			expected: [
				'title'          => 'T',
				'appId'          => 'dossiq',
				'state'          => 'available',
				'objectUuid'     => 'case-1',
				'workflowStepId' => 'step-2',
				'runUuid'        => 'run-1',
			],
			actual: $built
		);
	}//end testCopiesTrimmedFieldsUnderTheEngineNames()

	/**
	 * A JSON-string checklist is decoded; an empty, blank or undecodable one is left out.
	 *
	 * @return void
	 */
	public function testDecodesAUsableChecklistAndDropsTheRest(): void {
		$payload = new EngineTaskPayload();

		$this->assertSame(
			expected: [['id' => 'a', 'checked' => false]],
			actual: $payload->build(task: ['checklist' => '[{"id":"a","checked":false}]'], caseId: '')['checklist']
		);
		foreach (['[]', '  ', 'not json', '"a string"', null, []] as $unusable) {
			$this->assertArrayNotHasKey(key: 'checklist', array: $payload->build(task: ['checklist' => $unusable], caseId: ''));
		}
	}//end testDecodesAUsableChecklistAndDropsTheRest()
}//end class
