<?php

/**
 * Unit tests for ApprovalSource: each advice request still waiting on the
 * reader becomes a queue row that opens the case's advice card.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\Source\ApprovalSource;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\Source\ApprovalSource
 * @covers \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 *
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Queue\Source\WaitingRequestRows
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ApprovalSourceTest extends TestCase {
	use PersonalSourcesL10n;

	private SettingsService&MockObject $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * Wire a store answering the given rows, or throwing, and the given register.
	 *
	 * @param list<mixed>     $rows     The advice request rows.
	 * @param string          $register The configured register.
	 * @param \Throwable|null $failure  What the read throws, if anything.
	 */
	private function store(array $rows, string $register = ' dossiq ', ?\Throwable $failure = null): object {
		$store = new class($rows, $failure) {
			/** @var list<array<int|string, mixed>> */
			public array $searches = [];

			public function __construct(private array $rows, private ?\Throwable $failure) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->rows;
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ['register' => $register][$key] ?? $default);
		return $store;
	}//end store()

	private function source(): ApprovalSource {
		return new ApprovalSource($this->settings, $this->l10n());
	}//end source()

	public function testWaitingRequestsBecomeRowsThatOpenTheAdviceCard(): void {
		$store = $this->store(
			[
				['@self' => ['id' => 'adv-1'], 'subject' => 'Advies bouwplan', 'case' => ' case-1 ', 'deadline' => ' 2026-12-01 ', 'status' => 'requested'],
				['id' => 'adv-2', 'status' => ' Ontvangen '],
				['id' => 'adv-3', 'status' => 'expired'],
				['uuid' => 'adv-4', 'deadline' => ''],
				['subject' => 'No id'],
			]
		);

		$items = $this->source()->itemsFor('alice');

		$this->assertSame([['dossiq', 'adviesAanvraag', ['advisor' => 'alice', '_limit' => 100]]], $store->searches);
		$this->assertSame(['approvals:advice:adv-1', 'approvals:advice:adv-4'], array_map(static fn ($item): string => $item->identity(), $items));
		$this->assertSame(['Advies bouwplan', '', '2026-12-01'], [$items[0]->title, $items[0]->priority, $items[0]->dueAt]);
		$this->assertSame(['name' => 'CaseDetail', 'params' => ['id' => 'case-1'], 'hash' => '#case-decisions-panel'], $items[0]->route);
		$this->assertSame(['adv-4', null], [$items[1]->title, $items[1]->dueAt], 'a request without a subject is titled by its id');
		$this->assertSame(['name' => 'Cases'], $items[1]->route, 'a request without a case opens the case list');
	}//end testWaitingRequestsBecomeRowsThatOpenTheAdviceCard()

	public function testEveryFinishedStatusLeavesTheQueue(): void {
		$closed = ['received', 'expired', 'ontvangen', 'verwerkt', 'vervallen', 'ingetrokken', 'afgerond'];
		$rows = array_map(static fn (string $status): array => ['id' => 'adv-' . $status, 'status' => strtoupper($status)], $closed);
		$this->store($rows);

		$this->assertSame([], $this->source()->itemsFor('alice'));
	}//end testEveryFinishedStatusLeavesTheQueue()

	public function testTheSourceDescribesItself(): void {
		$source = $this->source();

		$this->assertSame('approvals', $source->name());
		$this->assertSame('Waiting for your signature', $source->label());
		$this->assertSame('A request leaves when you sign it, or when it is withdrawn.', $source->closesWhen());
		$this->assertSame([], $source->mechanisms());
	}//end testTheSourceDescribesItself()

	public function testWithoutARegisterTheSourceFails(): void {
		$this->store([], '  ');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossiq has no register configured yet.');
		$this->source()->itemsFor('alice');
	}//end testWithoutARegisterTheSourceFails()

	public function testWithoutOpenRegisterTheSourceFails(): void {
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getConfigValue')->willReturn('dossiq');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available, so this source cannot be read.');
		$this->source()->itemsFor('alice');
	}//end testWithoutOpenRegisterTheSourceFails()

	public function testAFailedReadIsRaisedAsARuntimeExceptionKeepingItsCause(): void {
		$cause = new \LogicException('schema adviesAanvraag not found');
		$this->store([], 'dossiq', $cause);

		try {
			$this->source()->itemsFor('alice');
			$this->fail('a failed read must not answer an empty queue');
		} catch (RuntimeException $e) {
			$this->assertSame('schema adviesAanvraag not found', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedReadIsRaisedAsARuntimeExceptionKeepingItsCause()
}//end class
