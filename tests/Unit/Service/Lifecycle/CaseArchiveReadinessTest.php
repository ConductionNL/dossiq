<?php

/**
 * Case Archive Readiness Test
 *
 * The archive dialog's checklist is read off the case: ended, a result, its
 * decisions, how many documents are final and how many tasks are still open.
 * A task list the engine could not read is unknown, never zero.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\CaseArchiveReadiness;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseArchiveReadiness
 */
class CaseArchiveReadinessTest extends TestCase {

	/**
	 * The readiness over a dossier and a task list.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents on the case.
	 * @param array<int, array<string, mixed>> $tasks     The tasks on the case.
	 * @param string                           $taskError What the engine reported, '' when it answered.
	 *
	 * @return CaseArchiveReadiness The readiness.
	 */
	private function readiness(array $documents, array $tasks, string $taskError = ''): CaseArchiveReadiness {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->method('getDossierForCase')->with('case-1')->willReturn(['informatieobjecten' => $documents]);

		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('forCase')->with('case-1', 'pieter')->willReturn($tasks);
		$inbox->method('lastError')->willReturn($taskError);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CaseArchiveReadiness(dossier: $dossier, tasks: $inbox, userSession: $session);
	}//end readiness()

	/**
	 * The checklist keyed by item id.
	 *
	 * @param array<int, array<string, mixed>> $checklist The checklist.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $checklist): array {
		return array_column($checklist, null, 'id');
	}//end byId()

	public function testAFinishedCaseIsReadItemByItem(): void {
		$checklist = $this->byId(
			$this->readiness(
				documents: [['status' => 'final'], ['status' => 'final'], ['status' => 'draft']],
				tasks: [['status' => 'completed'], ['status' => 'active']],
			)->checklist(
				caseId: 'case-1',
				case: ['endingAct' => 'finish', 'result' => 'rt-1', 'resultPublicLabel' => 'Deels openbaar gemaakt', 'decisions' => ['d-1']],
			)
		);

		$this->assertTrue($checklist['ended']['met']);
		$this->assertTrue($checklist['result']['met']);
		$this->assertSame('Deels openbaar gemaakt', $checklist['result']['detail']);
		$this->assertSame(['id' => 'decision', 'met' => true, 'count' => 1], $checklist['decision']);
		$this->assertSame(['id' => 'documents', 'met' => false, 'count' => 2, 'total' => 3], $checklist['documents']);
		$this->assertSame(['id' => 'openTasks', 'met' => false, 'count' => 1], $checklist['openTasks']);
	}//end testAFinishedCaseIsReadItemByItem()

	public function testAnOpenCaseIsNotReadyAndNothingIsAssumed(): void {
		$checklist = $this->byId(
			$this->readiness(documents: [], tasks: [])->checklist(caseId: 'case-1', case: [])
		);

		$this->assertFalse($checklist['ended']['met']);
		$this->assertFalse($checklist['result']['met']);
		$this->assertFalse($checklist['decision']['met']);
		$this->assertTrue($checklist['documents']['met'], 'no documents: none is still a draft');
		$this->assertTrue($checklist['openTasks']['met']);
	}//end testAnOpenCaseIsNotReadyAndNothingIsAssumed()

	public function testAnUnreadableTaskListIsUnknownNotZero(): void {
		$checklist = $this->byId(
			$this->readiness(documents: [], tasks: [], taskError: 'engine down')->checklist(caseId: 'case-1', case: ['endingAct' => 'finish'])
		);

		$this->assertSame(['id' => 'openTasks', 'met' => null], $checklist['openTasks']);
	}//end testAnUnreadableTaskListIsUnknownNotZero()
}//end class
