<?php

/**
 * An external inspector completes a checklist task from the portal.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-an-external-inspector-completes-a-checklist-task-from-the-portal
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionRunService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineInboxQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (class_exists(InspectionRunService::PORTAL_SUBJECT) === false) {
	require_once __DIR__ . '/Fixtures/PortalSubject.php';
}

/**
 * Tests for {@see InspectionRunService::completeForParty()}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionRunService
 * @uses   \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 */
final class InspectionRunPortalTest extends TestCase {

	/**
	 * The assigned party completes; the outcome is the rule's, not the caller's.
	 *
	 * @return void
	 */
	public function testTheAssignedPartyCompletesWithTheRulesOutcome(): void {
		$seam = self::seam(assignee: 'party:insp-7');
		$run = $this->service(seam: $seam)->completeForParty(
			subjectRef: 'insp-7',
			taskUuid: 'task-1',
			payload: ['items' => [['itemId' => 'q1', 'value' => 'ja'], ['itemId' => 'q2', 'value' => 'nee']], 'remarks' => 'Hoek', 'outcome' => 'conform']
		);

		$this->assertCount(1, $seam->completed);
		$this->assertSame('party:insp-7', $seam->completed[0]['subject']->partyReference());
		$this->assertSame('partly_conform', $seam->completed[0]['outcome']);
		$this->assertSame('Hoek', $seam->completed[0]['comment']);
		$this->assertSame('dossiq', $seam->completed[0]['viaApp'], 'dossiq names itself as the app the task is completed through');
		$this->assertSame('task-1', $run['id'] ?? $seam->completed[0]['uuid']);
	}//end testTheAssignedPartyCompletesWithTheRulesOutcome()

	/**
	 * Another party learns nothing and nothing is completed.
	 *
	 * @return void
	 */
	public function testAnotherPartyIsRefusedBeforeAnythingIsChecked(): void {
		$seam = self::seam(assignee: 'party:insp-7');

		$this->expectException(DoesNotExistException::class);
		try {
			$this->service(seam: $seam)->completeForParty(subjectRef: 'insp-8', taskUuid: 'task-1', payload: []);
		} finally {
			$this->assertSame([], $seam->completed);
		}
	}//end testAnotherPartyIsRefusedBeforeAnythingIsChecked()

	/**
	 * A run that leaves a required item open completes nothing.
	 *
	 * @return void
	 */
	public function testABrokenRunCompletesNothing(): void {
		$seam = self::seam(assignee: 'party:insp-7');

		$this->expectException(RuntimeException::class);
		try {
			$this->service(seam: $seam)->completeForParty(subjectRef: 'insp-7', taskUuid: 'task-1', payload: ['items' => [['itemId' => 'q2', 'value' => 'ja']]]);
		} finally {
			$this->assertSame([], $seam->completed);
		}
	}//end testABrokenRunCompletesNothing()

	/**
	 * A task without a frozen checklist is not a checklist task.
	 *
	 * @return void
	 */
	public function testATaskWithoutAChecklistIsRefused(): void {
		$seam = self::seam(assignee: 'party:insp-7', snapshot: null);

		$this->expectExceptionMessage('no checklist');
		$this->service(seam: $seam)->completeForParty(subjectRef: 'insp-7', taskUuid: 'task-1', payload: []);
	}//end testATaskWithoutAChecklistIsRefused()

	/**
	 * Without OpenRegister's seam the verb refuses.
	 *
	 * @return void
	 */
	public function testWithoutTheSeamItRefuses(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$this->expectExceptionMessage('not available');
		(new InspectionRunService($settings, $this->createMock(EngineInboxQuery::class)))->completeForParty(subjectRef: 'x', taskUuid: 't', payload: []);
	}//end testWithoutTheSeamItRefuses()

	/**
	 * The service over a seam.
	 *
	 * @param object $seam The seam.
	 *
	 * @return InspectionRunService The service.
	 */
	private function service(object $seam): InspectionRunService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => ($class === InspectionRunService::PORTAL_TASK_SERVICE ? $seam : null)
		);

		return new InspectionRunService($settings, $this->createMock(EngineInboxQuery::class));
	}//end service()

	/**
	 * A portal task seam holding one external task.
	 *
	 * @param string                    $assignee The stored party reference.
	 * @param array<string, mixed>|null $snapshot The frozen template.
	 *
	 * @return object The seam.
	 */
	private static function seam(string $assignee, ?array $snapshot = ['__default' => true]): object {
		if ($snapshot === ['__default' => true]) {
			$snapshot = [
				'@self' => ['id' => 't-1'],
				'name' => 'Fundering',
				'sections' => [[
					'items' => [
						['id' => 'q1', 'label' => 'Wapening', 'responseType' => 'yes_no_na', 'required' => true],
						['id' => 'q2', 'label' => 'Maatvoering', 'responseType' => 'yes_no_na'],
					],
				]],
			];
		}

		return new class($assignee, $snapshot) {
			/**
			 * Completions.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $completed = [];

			/**
			 * Constructor.
			 *
			 * @param string                    $assignee The stored party reference.
			 * @param array<string, mixed>|null $snapshot The frozen template.
			 */
			public function __construct(private string $assignee, private ?array $snapshot) {
			}//end __construct()

			/**
			 * The task, for its party only.
			 *
			 * @param object $subject The subject.
			 * @param string $uuid    The task.
			 *
			 * @return object The task.
			 *
			 * @throws DoesNotExistException For anyone else.
			 */
			public function show(object $subject, string $uuid): object {
				if (hash_equals($this->assignee, $subject->partyReference()) === false) {
					throw new DoesNotExistException('No such portal task.');
				}

				return self::task(['uuid' => $uuid, 'kind' => 'inspection', 'templateSnapshot' => $this->snapshot]);
			}

			/**
			 * Record a completion.
			 *
			 * @param object      $subject The subject.
			 * @param string      $uuid    The task.
			 * @param array       $answers The answers.
			 * @param string|null $comment The comment.
			 * @param array       $files   The files.
			 * @param string      $outcome The outcome.
			 *
			 * @return object The completed task.
			 */
			public function complete(object $subject, string $uuid, array $answers = [], ?string $comment = null, array $files = [], string $outcome = 'completed', string $viaApp = ''): object {
				unset($files);
				$this->completed[] = ['subject' => $subject, 'uuid' => $uuid, 'answers' => $answers, 'comment' => $comment, 'outcome' => $outcome, 'viaApp' => $viaApp];

				return self::task(['uuid' => $uuid, 'kind' => 'inspection', 'state' => 'completed', 'outcome' => $outcome, 'responses' => $answers, 'templateSnapshot' => $this->snapshot]);
			}

			/**
			 * A serialisable task.
			 *
			 * @param array<string, mixed> $data The fields.
			 *
			 * @return object The task.
			 */
			private static function task(array $data): object {
				return new class($data) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $data The fields.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * The fields.
					 *
					 * @return array<string, mixed> The fields.
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}
		};
	}//end seam()
}//end class
