<?php

/**
 * Unit tests for CoordinatorSeatSource: the open cases the reader coordinates
 * become rows, except the ones they already handle themselves, and a missing
 * register or store fails the source.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\Queue\Source\CoordinatorSeatSource;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\CoordinatorSeatSource
 * @covers \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CoordinatorSeatSourceTest extends TestCase {
	use PersonalSourcesL10n;

	private SettingsService&MockObject $settings;

	private CaseSeats&MockObject $seats;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->seats = $this->createMock(CaseSeats::class);
	}//end setUp()

	/**
	 * Wire a store answering the given case rows, and the given config.
	 *
	 * @param list<mixed>           $rows   The open case rows.
	 * @param array<string, string> $config Config values by key.
	 */
	private function store(array $rows, array $config = ['register' => ' dossiq ', 'case_schema' => 'case']): object {
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
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $config[$key] ?? $default);
		return $store;
	}//end store()

	private function source(): CoordinatorSeatSource {
		return new CoordinatorSeatSource($this->settings, $this->l10n(), $this->seats);
	}//end source()

	public function testCoordinatedOpenCasesBecomeRowsButNotTheOnesTheReaderHandles(): void {
		$this->seats->expects($this->once())->method('casesCoordinatedBy')->with('alice')->willReturn(['case-1', 'case-2', 'case-3', 'case-4']);
		$store = $this->store(
			[
				['@self' => ['id' => 'case-1'], 'title' => 'Bezwaar', 'priority' => 'high', 'deadline' => ' 2026-12-01 ', 'assignee' => 'bob'],
				['id' => 'case-2', 'assignee' => ' alice '],
				['uuid' => 'case-3', '@self' => ['id' => '  '], 'deadline' => ''],
				['id' => 'case-9', 'title' => 'Not coordinated'],
				['title' => 'No id'],
			]
		);

		$items = $this->source()->itemsFor('alice');

		$this->assertSame(['coordinator-seat:case:case-1', 'coordinator-seat:case:case-3'], array_map(static fn ($item): string => $item->identity(), $items));
		$this->assertSame(['Bezwaar', 'high', '2026-12-01'], [$items[0]->title, $items[0]->priority, $items[0]->dueAt]);
		$this->assertSame(['name' => 'CaseDetail', 'params' => ['id' => 'case-1']], $items[0]->route);
		$this->assertSame(['case-3', null], [$items[1]->title, $items[1]->dueAt]);
		$this->assertSame([['dossiq', 'case', ['isFinalStatus' => 0, '_limit' => 100]]], $store->searches);
	}//end testCoordinatedOpenCasesBecomeRowsButNotTheOnesTheReaderHandles()

	public function testABlankCaseSchemaFallsBackToCase(): void {
		$this->seats->method('casesCoordinatedBy')->willReturn(['case-1']);
		$store = $this->store([], ['register' => 'dossiq', 'case_schema' => '  ']);

		$this->assertSame([], $this->source()->itemsFor('alice'));
		$this->assertSame('case', $store->searches[0][1]);
	}//end testABlankCaseSchemaFallsBackToCase()

	public function testACoordinatorOfNothingIsNotRead(): void {
		$this->seats->method('casesCoordinatedBy')->willReturn([]);
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame([], $this->source()->itemsFor('alice'));
	}//end testACoordinatorOfNothingIsNotRead()

	public function testWithoutARegisterTheSourceFails(): void {
		$this->seats->method('casesCoordinatedBy')->willReturn(['case-1']);
		$this->store([], ['register' => '  ']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossiq has no register configured yet.');

		$this->source()->itemsFor('alice');
	}//end testWithoutARegisterTheSourceFails()

	public function testWithoutOpenRegisterTheSourceFails(): void {
		$this->seats->method('casesCoordinatedBy')->willReturn(['case-1']);
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getConfigValue')->willReturn('dossiq');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available, so this source cannot be read.');

		$this->source()->itemsFor('alice');
	}//end testWithoutOpenRegisterTheSourceFails()

	public function testAStoreErrorIsRethrownAsARuntimeException(): void {
		$this->seats->method('casesCoordinatedBy')->willReturn(['case-1']);
		$this->settings->method('getObjectService')->willReturn(
			new class {
				public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
					throw new \TypeError('bad filter');
				}
			}
		);
		$this->settings->method('getConfigValue')->willReturn('dossiq');

		try {
			$this->source()->itemsFor('alice');
			$this->fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			$this->assertSame('bad filter', $e->getMessage());
			$this->assertInstanceOf(\TypeError::class, $e->getPrevious());
		}
	}//end testAStoreErrorIsRethrownAsARuntimeException()

	public function testTheSourceDescribesItself(): void {
		$source = $this->source();

		$this->assertSame('coordinator-seat', $source->name());
		$this->assertSame('Cases you coordinate', $source->label());
		$this->assertSame('A case leaves when you hand the seat on, or when it is closed.', $source->closesWhen());
		$this->assertSame(['flow:DossiqTxSetStatusNode', 'flow:DossiqTxCreateSubCaseNode'], $source->mechanisms());
	}//end testTheSourceDescribesItself()
}//end class
