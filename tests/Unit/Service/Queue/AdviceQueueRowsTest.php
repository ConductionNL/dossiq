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
}//end class
