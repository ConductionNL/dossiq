<?php

/**
 * Failure paths of NoticeOfDefaultRows: an unreadable linked record, no penalty schema, and the finance payouts merged in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for NoticeOfDefaultRows when a read fails or a schema is not set.
 *
 * @covers \OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows
 * @uses   \OCA\Dossiq\Service\Termijn\NoticeListRun
 * @uses   \OCA\Dossiq\Service\Money\PayoutReader
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class NoticeOfDefaultRowsFailuresTest extends TestCase {

	/**
	 * Settings over a store, with the given config keys answering empty.
	 *
	 * @param FakeTermijnStore   $objects      The store.
	 * @param array<int, string> $unconfigured Keys that answer empty.
	 *
	 * @return SettingsService
	 */
	private function settings(FakeTermijnStore $objects, array $unconfigured = []): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (in_array($key, $unconfigured, true) === true ? '' : match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'notice_of_default_schema' => 'noticeOfDefault',
				'case_schema' => 'case',
				'payout_schema' => 'dwangsomUitbetaling',
				default => '',
			}),
		);

		return $settings;
	}//end settings()

	/**
	 * A store with one notice, its term, its case and a fixed penalty.
	 *
	 * @param FakeTermijnStore $objects The store to seed.
	 *
	 * @return FakeTermijnStore The seeded store.
	 */
	private function seeded(FakeTermijnStore $objects): FakeTermijnStore {
		$objects->seed('case', ['id' => 'case-1', 'identifier' => '2026-1', 'title' => 'Kapvergunning']);
		$objects->seed('deadlineInstance', ['id' => 'ti-1', 'case' => 'case-1']);
		$objects->seed('noticeOfDefault', ['id' => 'nod-1', 'deadlineInstance' => 'ti-1', 'receiptDate' => '2026-03-01', 'validityStatus' => 'valid']);
		$objects->seed('penaltyPaymentCalculation', ['id' => 'calc-1', 'noticeOfDefault' => 'nod-1', 'startDate' => '2026-03-15', 'definitiveAmount' => 5000]);

		return $objects;
	}//end seeded()

	/**
	 * A term or case that cannot be read leaves the case columns empty instead of failing the list.
	 *
	 * @return void
	 */
	public function testAnUnreadableLinkedRecordLeavesItsColumnsEmpty(): void {
		$objects = $this->seeded(
			new class extends FakeTermijnStore {
				/**
				 * Every single-object read fails.
				 *
				 * @param int|string      $id       The id.
				 * @param array|null      $_extend  Unused.
				 * @param bool            $files    Unused.
				 * @param string|int|null $register Unused.
				 * @param string|int|null $schema   Unused.
				 *
				 * @return never
				 */
				public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): never {
					throw new RuntimeException('store offline');
				}
			}
		);

		$row = (new NoticeOfDefaultRows(settingsService: $this->settings(objects: $objects)))->list()['notices'][0];

		self::assertSame('nod-1', $row['id']);
		self::assertSame(['', '', '', ''], [$row['caseId'], $row['caseIdentifier'], $row['caseTitle'], $row['caseLabel']]);
		self::assertSame('2026-03-01', $row['receiptDate']);
		self::assertSame('definitive', $row['penaltyState']);
		self::assertSame(50.0, $row['penaltyEuros']);
	}//end testAnUnreadableLinkedRecordLeavesItsColumnsEmpty()

	/**
	 * Without a penalty schema no notice has a penalty.
	 *
	 * @return void
	 */
	public function testWithoutAPenaltySchemaNoNoticeHasAPenalty(): void {
		$objects = $this->seeded(new FakeTermijnStore());
		$rows    = new NoticeOfDefaultRows(settingsService: $this->settings(objects: $objects, unconfigured: ['accruing_amount_schema']));

		$row = $rows->list()['notices'][0];

		self::assertSame('2026-1 Kapvergunning', $row['caseLabel']);
		self::assertSame(['none', 0.0, '', null, ''], [$row['penaltyState'], $row['penaltyEuros'], $row['penaltySince'], $row['financeState'], $row['accruingAmountId']]);
	}//end testWithoutAPenaltySchemaNoNoticeHasAPenalty()

	/**
	 * With a payout reader but no finance app answering, the stored payouts decide where the money stands.
	 *
	 * @return void
	 */
	public function testThePayoutReaderKeepsTheStoredPayoutsWhenFinanceIsSilent(): void {
		$objects = $this->seeded(new FakeTermijnStore());
		$objects->seed('dwangsomUitbetaling', ['id' => 'pay-1', 'penaltyPaymentCalculation' => 'calc-1', 'status' => 'voorbereid', 'payableId' => '']);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::atLeastOnce())->method('dispatchTyped');

		$rows = new NoticeOfDefaultRows(
			settingsService: $this->settings(objects: $objects),
			payoutReader: new PayoutReader(dispatcher: $dispatcher)
		);

		self::assertSame('prepared', $rows->list()['notices'][0]['financeState']);
	}//end testThePayoutReaderKeepsTheStoredPayoutsWhenFinanceIsSilent()
}//end class
