<?php

/**
 * Unit tests for ConsultationSource: each consultation still waiting on the
 * reader becomes a queue row that opens its parent case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\Source\ConsultationSource;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\ConsultationSource
 *
 * @uses \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Queue\Source\WaitingRequestRows
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ConsultationSourceTest extends TestCase {
	use PersonalSourcesL10n;

	/**
	 * The source over a store answering the given rows.
	 *
	 * @param list<mixed> $rows  The consultation rows.
	 * @param object|null $store Set to the store, for its recorded searches.
	 */
	private function source(array $rows, ?object &$store = null): ConsultationSource {
		$store = new class($rows) {
			/** @var list<array<int|string, mixed>> */
			public array $searches = [];

			public function __construct(private array $rows) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				return $this->rows;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ['register' => 'dossiq'][$key] ?? $default);

		return new ConsultationSource($settings, $this->l10n());
	}//end source()

	public function testWaitingConsultationsBecomeRowsThatOpenTheirCase(): void {
		$source = $this->source(
			[
				['id' => 'con-1', 'subject' => 'Welstandsadvies', 'parentCase' => ' case-1 ', 'priority' => 'high', 'latestResponseDate' => '2026-12-01', 'deadline' => '2026-11-01'],
				['id' => 'con-2', 'status' => 'Beantwoord'],
				['id' => 'con-3', 'status' => 'requested'],
				['id' => 'con-4', 'status' => 'vervallen'],
			],
			$store
		);

		$items = $source->itemsFor('bob');

		$this->assertSame([['dossiq', 'consultation', ['assignee' => 'bob', '_limit' => 100]]], $store->searches);
		$this->assertSame(['consultations:consultation:con-1', 'consultations:consultation:con-3'], array_map(static fn ($item): string => $item->identity(), $items));
		$this->assertSame(['Welstandsadvies', 'high', '2026-12-01'], [$items[0]->title, $items[0]->priority, $items[0]->dueAt], 'due by the latest response date, not the deadline');
		$this->assertSame(['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], $items[0]->route, 'the case itself, no tab');
		$this->assertSame(['con-3', '', null, ['name' => 'Cases']], [$items[1]->title, $items[1]->priority, $items[1]->dueAt, $items[1]->route]);
	}//end testWaitingConsultationsBecomeRowsThatOpenTheirCase()

	public function testAnAdviceStatusDoesNotCloseAConsultation(): void {
		$items = $this->source([['id' => 'con-1', 'status' => 'received'], ['id' => 'con-2', 'status' => 'ingetrokken'], ['id' => 'con-3', 'status' => 'afgerond']])->itemsFor('bob');

		$this->assertSame(['con-1'], array_map(static fn ($item): string => $item->subjectId, $items), 'a status this source does not know keeps the work waiting');
	}//end testAnAdviceStatusDoesNotCloseAConsultation()

	public function testTheSourceDescribesItself(): void {
		$source = $this->source([]);

		$this->assertSame('consultations', $source->name());
		$this->assertSame('Advice asked of you', $source->label());
		$this->assertSame('A consultation leaves when you answer it, or when it is withdrawn.', $source->closesWhen());
		$this->assertSame(['notification:onCreate', 'flow:DossiqEnsureCommitteeNode'], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
