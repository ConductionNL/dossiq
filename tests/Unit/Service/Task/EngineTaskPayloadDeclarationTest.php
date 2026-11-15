<?php

/**
 * EngineTaskPayload declaration and default tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\EngineTaskPayload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\EngineTaskPayload
 */
class EngineTaskPayloadDeclarationTest extends TestCase {

	/**
	 * A bare task is an available dossiq task with no case and no title text.
	 *
	 * @return void
	 */
	public function testABareTaskIsAnAvailableDossiqTask(): void {
		self::assertSame(
			['title' => '', 'appId' => 'dossiq', 'state' => 'available'],
			(new EngineTaskPayload())->build(task: [], caseId: '')
		);
	}//end testABareTaskIsAnAvailableDossiqTask()

	/**
	 * The register status is sent as the engine state, and every mapped field under its engine name.
	 *
	 * @return void
	 */
	public function testTheStatusAndEveryMappedFieldAreSent(): void {
		$built = (new EngineTaskPayload())->build(
			task: [
				'title'          => 'Toets',
				'status'         => 'claimed',
				'description'    => ' Lees het dossier ',
				'assignee'       => 'bob',
				'dueDate'        => '2026-12-01',
				'priority'       => 'high',
				'workflowStepId' => 'w-1',
				'flowRun'        => 'run-1',
				'flowNode'       => 'node-3',
			],
			caseId: 'case-4'
		);

		self::assertSame(
			[
				'title'          => 'Toets',
				'appId'          => 'dossiq',
				'state'          => 'claimed',
				'objectUuid'     => 'case-4',
				'description'    => 'Lees het dossier',
				'assignee'       => 'bob',
				'dueAt'          => '2026-12-01',
				'priority'       => 'high',
				'workflowStepId' => 'w-1',
				'runUuid'        => 'run-1',
				'nodeId'         => 'node-3',
			],
			$built
		);
	}//end testTheStatusAndEveryMappedFieldAreSent()

	/**
	 * Declared candidate users and metadata are sent; empty or non-list ones are not.
	 *
	 * @return void
	 */
	public function testDeclaredCandidateUsersAndMetadataAreSent(): void {
		$payload = new EngineTaskPayload();

		$built = $payload->build(
			task: ['candidateUsers' => ['a' => 'alice', 'b' => 'bob'], 'metadata' => ['form' => 'intake']],
			caseId: ''
		);
		self::assertSame(['alice', 'bob'], $built['candidateUsers']);
		self::assertSame(['form' => 'intake'], $built['metadata']);
		self::assertArrayNotHasKey('candidateGroups', $built);

		$empty = $payload->build(task: ['candidateUsers' => [], 'metadata' => 'form', 'candidateGroups' => 'team'], caseId: '');
		self::assertArrayNotHasKey('candidateUsers', $empty);
		self::assertArrayNotHasKey('metadata', $empty);
		self::assertArrayNotHasKey('candidateGroups', $empty);
	}//end testDeclaredCandidateUsersAndMetadataAreSent()

	/**
	 * A checklist already stored as a list is sent as it is.
	 *
	 * @return void
	 */
	public function testANativeChecklistIsSentAsItIs(): void {
		$checklist = [['id' => 'a', 'checked' => true], ['id' => 'b', 'checked' => false]];

		self::assertSame($checklist, (new EngineTaskPayload())->build(task: ['checklist' => $checklist], caseId: '')['checklist']);
	}//end testANativeChecklistIsSentAsItIs()
}//end class
