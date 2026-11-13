<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where an advice request and a consultation on the personal queue lead: the
 * case, on the tab that holds the advice card, and never a tab the case does
 * not have.
 *
 * @spec openspec/changes/advice-and-consultation-on-the-case/specs/advice-management/spec.md#requirement-a-handler-asks-for-advice-from-the-case-req-adv-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\Source\ApprovalSource;
use OCA\Dossiq\Service\Queue\Source\ConsultationSource;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\ApprovalSource
 * @covers \OCA\Dossiq\Service\Queue\Source\ConsultationSource
 * @uses \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 */
class AdviceQueueRowsTest extends TestCase {

	/**
	 * Settings over an object service that answers fixed rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Schema to rows.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settingsAnswering(array $rows): SettingsService {
		$objects = new class ($rows) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Schema to rows.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return ($this->rows[$schema] ?? []);
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq']]);
		return $settings;
	}//end settingsAnswering()

	/**
	 * An open advice request opens its case on the tab with the advice card;
	 * a received or expired one has left the queue.
	 *
	 * @return void
	 */
	public function testAnOpenAdviceRequestOpensTheAdviceTabAndAnAnsweredOneIsGone(): void {
		$source = new ApprovalSource(
			settings: $this->settingsAnswering(rows: ['adviesAanvraag' => [
				['id' => 'adv-open', 'case' => 'case-1', 'subject' => 'Welstand', 'status' => 'requested'],
				['id' => 'adv-received', 'case' => 'case-1', 'subject' => 'Verkeer', 'status' => 'received'],
				['id' => 'adv-expired', 'case' => 'case-1', 'subject' => 'Milieu', 'status' => 'expired'],
			]]),
			l10n: $this->createMock(originalClassName: IL10N::class),
		);

		$items = $source->itemsFor(userId: 'alice');

		$this->assertCount(expectedCount: 1, haystack: $items);
		$this->assertSame(
			expected: ['name' => 'CaseDetail', 'params' => ['id' => 'case-1'], 'hash' => '#case-decisions-panel'],
			actual: $items[0]->route,
		);
	}//end testAnOpenAdviceRequestOpensTheAdviceTabAndAnAnsweredOneIsGone()

	/**
	 * A consultation opens its case, with no tab the case does not have.
	 *
	 * @return void
	 */
	public function testAConsultationOpensItsCase(): void {
		$source = new ConsultationSource(
			settings: $this->settingsAnswering(rows: ['consultation' => [
				['id' => 'con-1', 'parentCase' => 'case-2', 'subject' => 'Brandweer', 'status' => 'open'],
			]]),
			l10n: $this->createMock(originalClassName: IL10N::class),
		);

		$items = $source->itemsFor(userId: 'alice');

		$this->assertCount(expectedCount: 1, haystack: $items);
		$this->assertSame(expected: ['name' => 'CaseDetail', 'params' => ['id' => 'case-2']], actual: $items[0]->route);
	}//end testAConsultationOpensItsCase()

	/**
	 * Every field of every item both sources answer, over open, closed,
	 * id-less and case-less rows.
	 *
	 * @return void
	 */
	public function testEveryFieldOfBothSourcesItems(): void {
		$settings = $this->settingsAnswering(rows: [
			'adviesAanvraag' => [
				['id' => 'adv-1', 'case' => ' case-1 ', 'subject' => 'Welstand', 'status' => 'requested', 'deadline' => '2026-12-01'],
				['id' => 'adv-2', 'status' => ' Ontvangen '],
				['id' => 'adv-3', 'case' => '', 'priority' => 'high'],
				['subject' => 'No id', 'status' => 'requested'],
			],
			'consultation' => [
				['id' => 'con-1', 'parentCase' => 'case-2', 'subject' => 'Brandweer', 'status' => 'open', 'priority' => 'high', 'latestResponseDate' => '2026-12-02'],
				['id' => 'con-2', 'status' => 'BEANTWOORD'],
				['id' => 'con-3'],
				['status' => 'open'],
			],
		]);
		$l10n = $this->createMock(originalClassName: IL10N::class);

		$items = array_merge(
			(new ApprovalSource(settings: $settings, l10n: $l10n))->itemsFor(userId: 'alice'),
			(new ConsultationSource(settings: $settings, l10n: $l10n))->itemsFor(userId: 'alice')
		);

		$this->assertSame(
			expected: [
				['approvals', 'advice', 'adv-1', 'Welstand', '', '2026-12-01', null, ['name' => 'CaseDetail', 'params' => ['id' => 'case-1'], 'hash' => '#case-decisions-panel']],
				['approvals', 'advice', 'adv-3', 'adv-3', '', null, null, ['name' => 'Cases']],
				['consultations', 'consultation', 'con-1', 'Brandweer', 'high', '2026-12-02', null, ['name' => 'CaseDetail', 'params' => ['id' => 'case-2']]],
				['consultations', 'consultation', 'con-3', 'con-3', '', null, null, ['name' => 'Cases']],
			],
			actual: array_map(
				static fn ($item): array => [$item->source, $item->subjectType, $item->subjectId, $item->title, $item->priority, $item->dueAt, $item->coveredFor, $item->route],
				$items
			)
		);
	}//end testEveryFieldOfBothSourcesItems()
}//end class
