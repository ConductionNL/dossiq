<?php

/**
 * Unit tests for NoticeOfDefaultRows: each notice of default by its case, with
 * the penalty it started and where the money stands (board DqTermijnen,
 * "Ingebrekestellingen en dwangsom").
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NoticeOfDefaultRows.
 */
class NoticeOfDefaultRowsTest extends TestCase {
	/**
	 * The fake object store the rows read.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The service under test.
	 *
	 * @var NoticeOfDefaultRows
	 */
	private NoticeOfDefaultRows $rows;

	/**
	 * Three cases with a term, three notices and two penalty calculations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->rows    = new NoticeOfDefaultRows(
			settingsService: $this->settings(objects: $this->objects, noticeSchema: 'noticeOfDefault')
		);

		$this->caseWithTerm(n: 4863, title: 'Kapvergunning Parkstraat');
		$this->caseWithTerm(n: 107, title: 'Kapotte bank en zwerfafval bij de speeltuin Havenstraat');
		$this->caseWithTerm(n: 206, title: 'Kapvergunning Molenweg 4');
		$this->notice(n: 4863, receiptDate: '2026-08-07', validity: 'valid');
		$this->notice(n: 107, receiptDate: '2026-10-02', validity: 'valid');
		$this->notice(n: 206, receiptDate: '2026-10-11', validity: 'premaat');
		$this->objects->seed(
			schema: 'penaltyPaymentCalculation',
			object: [
				'id'               => 'calc-4863',
				'noticeOfDefault'  => 'nod-4863',
				'deadlineInstance' => 'ti-4863',
				'startDate'        => '2026-08-21',
				'cumulativeAmount' => 144200,
				'definitiveAmount' => 144200,
				'status'           => 'completed',
			]
		);
		$this->objects->seed(
			schema: 'penaltyPaymentCalculation',
			object: [
				'id'               => 'calc-107',
				'noticeOfDefault'  => 'nod-107',
				'deadlineInstance' => 'ti-107',
				'startDate'        => '2026-10-16',
				'cumulativeAmount' => 20700,
				'status'           => 'lopend',
			]
		);
	}

	/**
	 * Each notice names its case, newest receipt first.
	 *
	 * @return void
	 */
	public function testNamesEachNoticeByItsCase(): void {
		$rows = $this->rows->list()['notices'];

		self::assertSame(expected: ['nod-206', 'nod-107', 'nod-4863'], actual: array_column(array: $rows, column_key: 'id'));
		$parkstraat = $rows[2];
		self::assertSame(expected: 'case-4863', actual: $parkstraat['caseId']);
		self::assertSame(expected: '2026-4863', actual: $parkstraat['caseIdentifier']);
		self::assertSame(expected: '2026-4863 Kapvergunning Parkstraat', actual: $parkstraat['caseLabel']);
		self::assertSame(expected: '2026-08-07', actual: $parkstraat['receiptDate']);
		self::assertSame(expected: 'valid', actual: $parkstraat['validityStatus']);
	}

	/**
	 * A fixed penalty reads its definitive amount and is ready for payout; a
	 * running one its amount so far, since it started, with the amount not
	 * fixed yet; a notice without a penalty neither.
	 *
	 * @return void
	 */
	public function testReadsThePenaltyAndWhereTheMoneyStands(): void {
		$byId = array_column(array: $this->rows->list()['notices'], column_key: null, index_key: 'id');

		self::assertSame(expected: ['definitive', 1442.0, '2026-08-21', 'payout-due'], actual: $this->money(row: $byId['nod-4863']));
		self::assertSame(expected: 'calc-4863', actual: $byId['nod-4863']['accruingAmountId']);
		self::assertSame(expected: 'calc-107', actual: $byId['nod-107']['accruingAmountId']);
		self::assertSame(expected: '', actual: $byId['nod-206']['accruingAmountId']);
		self::assertSame(expected: ['running', 207.0, '2026-10-16', 'amount-open'], actual: $this->money(row: $byId['nod-107']));
		self::assertSame(expected: ['none', 0.0, '', null], actual: $this->money(row: $byId['nod-206']));
	}

	/**
	 * A penalty frozen by an objection or stopped by a decision without a fixed
	 * amount yet still reads as running money, not as paid.
	 *
	 * @return void
	 */
	public function testAPenaltyWithoutAFixedAmountIsNotSentToFinance(): void {
		$this->objects->seed(
			schema: 'penaltyPaymentCalculation',
			object: [
				'id'               => 'calc-206',
				'noticeOfDefault'  => 'nod-206',
				'deadlineInstance' => 'ti-206',
				'startDate'        => '2026-10-20',
				'cumulativeAmount' => 5000,
				'status'           => 'objection-bevroren',
			]
		);
		$row = array_column(array: $this->rows->list()['notices'], column_key: null, index_key: 'id')['nod-206'];

		self::assertSame(expected: 'running', actual: $row['penaltyState']);
		self::assertSame(expected: 'amount-open', actual: $row['financeState']);
	}

	/**
	 * A prepared payout says where it stands: stored only, it is prepared; taken
	 * by the finance app, a payment signal went out; then paid, rejected or on
	 * hold for an objection.
	 *
	 * @return void
	 */
	public function testAFixedPenaltyReadsItsPayoutStatus(): void {
		$cases = [
			[['status' => 'voorbereid'], 'prepared'],
			[['status' => 'voorbereid', 'payableId' => 'pay-1'], 'signal-sent'],
			[['status' => 'in-handling', 'payableId' => 'pay-1'], 'signal-sent'],
			[['status' => 'paid', 'payableId' => 'pay-1'], 'paid'],
			[['status' => 'rejected'], 'rejected'],
			[['status' => 'on-hold-objection'], 'on-hold'],
		];
		foreach ($cases as $n => [$payout, $expected]) {
			$objects = clone $this->objects;
			$objects->seed(
				schema: 'dwangsomUitbetaling',
				object: ['id' => 'pay-row-'.$n, 'penaltyPaymentCalculation' => 'calc-4863'] + $payout
			);
			$rows = new NoticeOfDefaultRows(settingsService: $this->settings(objects: $objects, noticeSchema: 'noticeOfDefault'));
			$row  = array_column(array: $rows->list()['notices'], column_key: null, index_key: 'id')['nod-4863'];

			self::assertSame(expected: $expected, actual: $row['financeState'], message: json_encode($payout));
		}
	}

	/**
	 * Without a notice schema configured there is nothing to list.
	 *
	 * @return void
	 */
	public function testIsEmptyWithoutANoticeSchema(): void {
		$rows = new NoticeOfDefaultRows(settingsService: $this->settings(objects: $this->objects, noticeSchema: ''));

		self::assertSame(expected: ['notices' => []], actual: $rows->list());
	}

	/**
	 * Seed a case and its statutory term.
	 *
	 * @param int    $n     The case number.
	 * @param string $title The case title.
	 *
	 * @return void
	 */
	private function caseWithTerm(int $n, string $title): void {
		$this->objects->seed(
			schema: 'case',
			object: ['id' => 'case-'.$n, 'identifier' => sprintf('2026-%04d', $n), 'title' => $title]
		);
		$this->objects->seed(schema: 'deadlineInstance', object: ['id' => 'ti-'.$n, 'case' => 'case-'.$n]);
	}

	/**
	 * Seed a notice of default on a case's term.
	 *
	 * @param int    $n           The case number.
	 * @param string $receiptDate The date the notice came in.
	 * @param string $validity    The notice's validity status.
	 *
	 * @return void
	 */
	private function notice(int $n, string $receiptDate, string $validity): void {
		$this->objects->seed(
			schema: 'noticeOfDefault',
			object: [
				'id'               => 'nod-'.$n,
				'deadlineInstance' => 'ti-'.$n,
				'receiptDate'      => $receiptDate,
				'validityStatus'   => $validity,
			]
		);
	}

	/**
	 * The penalty and finance fields of a row, in a fixed order.
	 *
	 * @param array<string, mixed> $row A notice row.
	 *
	 * @return list<mixed> State, euros, since and finance state.
	 */
	private function money(array $row): array {
		return [$row['penaltyState'], $row['penaltyEuros'], $row['penaltySince'], $row['financeState']];
	}

	/**
	 * Settings that point the schema keys at the fake store's slugs.
	 *
	 * @param FakeTermijnStore $objects      The fake store.
	 * @param string           $noticeSchema The notice schema slug, or '' for none.
	 *
	 * @return SettingsService
	 */
	private function settings(FakeTermijnStore $objects, string $noticeSchema): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'notice_of_default_schema' => $noticeSchema,
				'case_schema' => 'case',
				'payout_schema' => 'dwangsomUitbetaling',
				default => '',
			},
		);

		return $settings;
	}
}
