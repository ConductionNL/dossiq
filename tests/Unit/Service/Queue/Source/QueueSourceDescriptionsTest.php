<?php

/**
 * The approval, consultation, planned-action and assigned-cases sources
 * describe themselves, and the planned-action and assigned-cases sources
 * read only what they should.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Service\Queue\Source\ApprovalSource;
use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\Queue\Source\ConsultationSource;
use OCA\Dossiq\Service\Queue\Source\PlannedActionSource;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Descriptions and reads of four personal queue sources.
 */
class QueueSourceDescriptionsTest extends TestCase {
	use PersonalSourcesL10n;

	/**
	 * Each source names itself, says what puts a row there and what takes it away, and which mechanisms it rides on.
	 *
	 * @return void
	 */
	public function testTheSourcesDescribeThemselves(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$approval = new ApprovalSource(settings: $settings, l10n: $this->l10n());
		$consult  = new ConsultationSource(settings: $settings, l10n: $this->l10n());
		$planned  = new PlannedActionSource(plannedActions: $this->createMock(originalClassName: PlannedActionService::class), l10n: $this->l10n());
		$assigned = new AssignedCasesSource(settings: $settings, l10n: $this->l10n());

		$this->assertSame(expected: 'approvals', actual: $approval->name());
		$this->assertSame(expected: 'Waiting for your signature', actual: $approval->label());
		$this->assertSame(expected: 'A request leaves when you sign it, or when it is withdrawn.', actual: $approval->closesWhen());
		$this->assertSame(expected: [], actual: $approval->mechanisms());

		$this->assertSame(expected: 'Advice asked of you', actual: $consult->label());
		$this->assertSame(expected: 'A consultation leaves when you answer it, or when it is withdrawn.', actual: $consult->closesWhen());
		$this->assertSame(expected: ['notification:onCreate', 'flow:DossiqEnsureCommitteeNode'], actual: $consult->mechanisms());

		$this->assertSame(expected: 'Planned on a case', actual: $planned->label());
		$this->assertSame(
			expected: 'A planned action leaves when you complete it, which plans whatever the case type says comes after it.',
			actual: $planned->closesWhen()
		);
		$this->assertSame(expected: [], actual: $planned->mechanisms());

		$this->assertSame(expected: 'Cases assigned to you', actual: $assigned->label());
		$this->assertSame(expected: 'A case leaves when it is closed or goes to somebody else.', actual: $assigned->closesWhen());
		$this->assertSame(
			expected: ['notification:caseAssigned', 'notification:caseHandoffIntake', 'notification:termResumedAfterPause'],
			actual: $assigned->mechanisms()
		);
	}//end testTheSourcesDescribeThemselves()

	/**
	 * Without a user there are no planned actions to show, and the service is not asked.
	 *
	 * @return void
	 */
	public function testNoUserHasNoPlannedActions(): void {
		$service = $this->createMock(originalClassName: PlannedActionService::class);
		$service->expects($this->never())->method('ownedBy');

		$this->assertSame(expected: [], actual: (new PlannedActionSource(plannedActions: $service, l10n: $this->l10n()))->itemsFor(userId: ''));
	}//end testNoUserHasNoPlannedActions()

	/**
	 * Assigned cases are read from the case schema, falling back to `case` when
	 * none is configured, as the person's open cases; a row without an id is skipped.
	 *
	 * @return void
	 */
	public function testAssignedCasesAreReadFromTheCaseSchemaAndRowsWithoutAnIdSkipped(): void {
		$objects = new class {
			/**
			 * The queries asked.
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $asked = [];

			/**
			 * Answer two cases, one without an id.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->asked[] = [$register, $schema, $filters];

				return [
					['id' => 'case-1', 'title' => 'Bouwvergunning', 'priority' => 'high'],
					['title' => 'No id'],
				];
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register'    => 'dossiq',
				'case_schema' => '  ',
				default       => $default,
			}
		);

		$items = (new AssignedCasesSource(settings: $settings, l10n: $this->l10n()))->itemsFor(userId: 'alice');

		$this->assertSame(expected: 'dossiq', actual: $objects->asked[0][0]);
		$this->assertSame(expected: 'case', actual: $objects->asked[0][1]);
		$this->assertSame(expected: 'alice', actual: $objects->asked[0][2]['assignee']);
		$this->assertSame(expected: 0, actual: $objects->asked[0][2]['isFinalStatus']);
		$this->assertCount(expectedCount: 1, haystack: $items);
		$this->assertSame(expected: 'case-1', actual: $items[0]->subjectId);
		$this->assertSame(expected: 'Bouwvergunning', actual: $items[0]->title);
		$this->assertSame(expected: 'high', actual: $items[0]->priority);
		$this->assertSame(expected: ['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], actual: $items[0]->route);
	}//end testAssignedCasesAreReadFromTheCaseSchemaAndRowsWithoutAnIdSkipped()
}//end class
