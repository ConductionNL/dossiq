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
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseArchiveReadiness
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
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
		$register = new InMemoryRegister();
		foreach ($documents as $at => $document) {
			$register->seed('informatieobject', 'io-' . $at, $document);
			$register->seed('zaakinformatieobject', 'j-' . $at, ['case' => 'case-1', 'informatieobject' => 'io-' . $at]);
		}

		// A document of another case is not counted.
		$register->seed('informatieobject', 'io-other', ['status' => 'draft']);
		$register->seed('zaakinformatieobject', 'j-other', ['case' => 'case-2', 'informatieobject' => 'io-other']);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
				'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
			][$key] ?? $default
		);

		$inbox = $this->createMock(EngineTaskInbox::class);
		$inbox->method('forCase')->with('case-1', 'pieter')->willReturn($tasks);
		$inbox->method('lastError')->willReturn($taskError);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$endings = $this->createMock(CaseEndingActs::class);
		$endings->method('isEnded')->willReturnCallback(static fn (array $case): bool => trim((string)($case['endingAct'] ?? '')) !== '');

		return new CaseArchiveReadiness(settingsService: $settings, tasks: $inbox, userSession: $session, endings: $endings);
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
