<?php

/**
 * The chain of holdings a case passes through.
 *
 * What these tests watch for is the failure D-2 names: a chain with a HOLE. A
 * hole is worse than no chain, because a reader takes it for an answer. So the
 * assertions are about the join between two holdings, not about either holding
 * on its own: the moment one ends is the moment the next begins, exactly one is
 * open, and the sequence never repeats.
 *
 * The store is a real in-memory register rather than a per-call stub, because
 * every assertion here is about what came back OUT after a write. A stub that
 * answered the same row whatever was saved would pass a close that never
 * closed anything.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$this->close(...)` call from
 * CaseCustodyChain::move() reddens
 * testATransferClosesOneHoldingAndOpensTheNext on the `until` assertion, and
 * testExactlyOneHoldingIsOpenAfterAMove on the count. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Opening, closing and reading holdings.
 *
 * @covers \OCA\Dossiq\Service\Custody\CaseCustodyChain
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseCustodyChainTest extends TestCase {

	/**
	 * The store every service under test reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Wire a configured instance holding one open case owned by Vergunningen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: [
				'title' => 'Dakkapel Prinsengracht 12',
				'assignedGroup' => 'vergunningen',
				'assignee' => 'jan',
				'startDate' => '2026-03-03',
			],
		);
	}//end setUp()

	/**
	 * A move closes the holding that was open and opens the next on the same moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testATransferClosesOneHoldingAndOpensTheNext(): void {
		$chain = $this->chain();

		$chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);

		$opened = $chain->move(
			caseId: 'case-1',
			organisationUnit: 'toezicht',
			handler: 'sofie',
			reason: 'Dit is handhaving',
			movedBy: 'jan',
			at: '2026-04-12T14:30:00+02:00',
		);

		$holdings = $chain->holdings(caseId: 'case-1');
		self::assertCount(2, $holdings, 'A move adds a holding, it does not replace one.');

		[$first, $second] = $holdings;

		self::assertSame(
			$second['from'],
			$first['until'],
			'The moment the first holding ends is the moment the second begins, or the chain has a gap.',
		);
		self::assertFalse($first['open'], 'The holding that was open must be closed by the move.');
		self::assertTrue($second['open'], 'The holding the case moved into is the one that is open.');
		self::assertSame('toezicht', $second['organisationUnit']);
		self::assertSame('sofie', $second['handler']);
		self::assertSame('Dit is handhaving', $second['reason'], 'The reason for the move is on the new holding.');
		self::assertSame('jan', $second['movedBy'], 'And so is the person who made it.');
		self::assertSame($second, $opened, 'move() returns the holding that is now open.');
	}//end testATransferClosesOneHoldingAndOpensTheNext()

	/**
	 * After any move, exactly one holding is open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testExactlyOneHoldingIsOpenAfterAMove(): void {
		$chain = $this->chain();
		$chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);

		foreach (['toezicht', 'handhaving', 'juridisch'] as $index => $unit) {
			$chain->move(
				caseId: 'case-1',
				organisationUnit: $unit,
				handler: '',
				reason: 'Move ' . $index,
				movedBy: 'jan',
			);
		}

		$open = array_values(
			array_filter(
				$chain->holdings(caseId: 'case-1'),
				static function (array $holding): bool {
					return ($holding['open'] === true);
				},
			)
		);

		self::assertCount(1, $open, 'A case is held by exactly one unit, never by two and never by nobody.');
		self::assertSame('juridisch', $open[0]['organisationUnit'], 'And it is the unit of the last move.');
		self::assertSame($open[0], $chain->openHoldingFor(caseId: 'case-1'));
	}//end testExactlyOneHoldingIsOpenAfterAMove()

	/**
	 * The holdings run in sequence, with no repeat and no gap in the numbering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testTheHoldingsAreNumberedInOrderFromOne(): void {
		$chain = $this->chain();
		$chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);
		$chain->move(caseId: 'case-1', organisationUnit: 'toezicht', handler: '', reason: 'One', movedBy: 'jan');
		$chain->move(caseId: 'case-1', organisationUnit: 'handhaving', handler: '', reason: 'Two', movedBy: 'jan');

		$sequences = array_map(
			static function (array $holding): int {
				return (int)$holding['sequence'];
			},
			$chain->holdings(caseId: 'case-1'),
		);

		self::assertSame([1, 2, 3], $sequences, 'Two holdings with the same number cannot be read back in order.');
	}//end testTheHoldingsAreNumberedInOrderFromOne()

	/**
	 * A second begin() on a case that already has an open holding does nothing.
	 *
	 * This is the property the backfill leans on to be re-runnable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testBeginningTwiceDoesNotDuplicateTheFirstHolding(): void {
		$chain = $this->chain();

		$first = $chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);
		$second = $chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered again',
			movedBy: 'jan',
		);

		self::assertNotNull($first);
		self::assertNull($second, 'A case that already has an open holding is left alone.');
		self::assertCount(1, $chain->holdings(caseId: 'case-1'));
	}//end testBeginningTwiceDoesNotDuplicateTheFirstHolding()

	/**
	 * A case with no holdings has no open one, and reads as an empty chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testAnEmptyChainAnswersNothingRatherThanGuessing(): void {
		$chain = $this->chain();

		self::assertSame([], $chain->holdings(caseId: 'case-1'));
		self::assertNull($chain->openHoldingFor(caseId: 'case-1'));
	}//end testAnEmptyChainAnswersNothingRatherThanGuessing()

	/**
	 * The chain under test, wired to the in-memory store.
	 *
	 * @return CaseCustodyChain The chain.
	 */
	private function chain(): CaseCustodyChain {
		return new CaseCustodyChain(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end chain()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_custody_schema' => 'caseCustody',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
