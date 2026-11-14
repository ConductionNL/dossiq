<?php

/**
 * StatusChecklist with a per-task declaration reader wired.
 *
 * A case type can switch a checklist task off, and a task it configures
 * carries that configuration on its createTask action.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\Task\TaskDeclarationReader;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusChecklist
 *
 * @uses \OCA\Dossiq\Service\Support\StoredArray
 */
class StatusChecklistDeclarationTest extends TestCase {

	/**
	 * The reader over a two-item status and the given declaration reader.
	 *
	 * @param TaskDeclarationReader $declarations The per-task declaration reader.
	 *
	 * @return StatusChecklist The reader under test.
	 */
	private function checklist(TaskDeclarationReader $declarations): StatusChecklist {
		$lookup = $this->createMock(StatusTypeLookup::class);
		$lookup->method('rowFor')->willReturn(['checklist' => [['title' => 'Intake call'], ['title' => 'Site visit', 'required' => true]]]);

		$inbox = $this->getMockBuilder(EngineTaskInbox::class)->disableOriginalConstructor()->getMock();
		$inbox->method('forCase')->willReturn([]);
		$inbox->method('lastError')->willReturn('');

		return new StatusChecklist($lookup, $inbox, new NullLogger(), $declarations);
	}//end checklist()

	/**
	 * A disabled task is not created; a configured one carries its declaration.
	 *
	 * @return void
	 */
	public function testADisabledTaskIsSkippedAndAConfiguredOneCarriesItsDeclaration(): void {
		$configured = ['enabled' => true, 'candidateGroups' => ['toezicht']] + TaskDeclaration::NONE;
		$reader = $this->getMockBuilder(TaskDeclarationReader::class)->disableOriginalConstructor()->getMock();
		$reader->expects($this->exactly(2))
			->method('forTask')
			->willReturnCallback(
				static function (string $caseId, string $statusTypeId, string $title) use ($configured): array {
					self::assertSame('case-1', $caseId);
					self::assertSame('st-1', $statusTypeId);
					return $title === 'Intake call' ? ['enabled' => false] + TaskDeclaration::NONE : $configured;
				}
			);

		$actions = $this->checklist($reader)->actionsFor(statusTypeId: 'st-1', case: ['uuid' => 'case-1'], actor: 'jan');

		$this->assertSame(
			[
				[
					'type' => 'createTask',
					'title' => 'Site visit',
					'workflowStepId' => 'st-1',
					'assignee' => '{{ case.assignee }}',
					'declaration' => $configured,
				],
			],
			$actions
		);
	}//end testADisabledTaskIsSkippedAndAConfiguredOneCarriesItsDeclaration()
}//end class
