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
 * @spec openspec/changes/notices-of-default-by-case/specs/termijn-reporting/spec.md
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
	private FakeTermijnStore $objects;
	private NoticeOfDefaultRows $rows;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->rows = new NoticeOfDefaultRows($this->settings(objects: $this->objects, noticeSchema: 'noticeOfDefault'));

		$this->caseWithTerm(n: 4863, title: 'Kapvergunning Parkstraat');
		$this->caseWithTerm(n: 107, title: 'Kapotte bank en zwerfafval bij de speeltuin Havenstraat');
		$this->caseWithTerm(n: 206, title: 'Kapvergunning Molenweg 4');
		$this->objects->seed('noticeOfDefault', ['id' => 'nod-4863', 'deadlineInstance' => 'ti-4863', 'receiptDate' => '2026-08-07', 'validityStatus' => 'valid']);
		$this->objects->seed('noticeOfDefault', ['id' => 'nod-107', 'deadlineInstance' => 'ti-107', 'receiptDate' => '2026-10-02', 'validityStatus' => 'valid']);
		$this->objects->seed('noticeOfDefault', ['id' => 'nod-206', 'deadlineInstance' => 'ti-206', 'receiptDate' => '2026-10-11', 'validityStatus' => 'premaat']);
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-4863', 'noticeOfDefault' => 'nod-4863', 'deadlineInstance' => 'ti-4863', 'startDate' => '2026-08-21', 'cumulativeAmount' => 144200, 'definitiveAmount' => 144200, 'status' => 'completed']);
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-107', 'noticeOfDefault' => 'nod-107', 'deadlineInstance' => 'ti-107', 'startDate' => '2026-10-16', 'cumulativeAmount' => 20700, 'status' => 'lopend']);
	}

	/**
	 * Each notice names its case, newest receipt first.
	 *
	 * @return void
	 */
	public function testNamesEachNoticeByItsCase(): void {
		$rows = $this->rows->list()['notices'];

		self::assertSame(['nod-206', 'nod-107', 'nod-4863'], array_column($rows, 'id'));
		$parkstraat = $rows[2];
		self::assertSame('case-4863', $parkstraat['caseId']);
		self::assertSame('2026-4863', $parkstraat['caseIdentifier']);
		self::assertSame('2026-4863 Kapvergunning Parkstraat', $parkstraat['caseLabel']);
		self::assertSame('2026-08-07', $parkstraat['receiptDate']);
		self::assertSame('valid', $parkstraat['validityStatus']);
	}

	/**
	 * A fixed penalty reads its definitive amount and has gone to finance as a
	 * payment signal; a running one its amount so far, since it started, with
	 * the amount not fixed yet; a notice without a penalty neither.
	 *
	 * @return void
	 */
	public function testReadsThePenaltyAndWhereTheMoneyStands(): void {
		$byId = array_column($this->rows->list()['notices'], null, 'id');

		self::assertSame(['definitive', 1442.0, '2026-08-21', 'signal-sent'], [$byId['nod-4863']['penaltyState'], $byId['nod-4863']['penaltyEuros'], $byId['nod-4863']['penaltySince'], $byId['nod-4863']['financeState']]);
		self::assertSame(['running', 207.0, '2026-10-16', 'amount-open'], [$byId['nod-107']['penaltyState'], $byId['nod-107']['penaltyEuros'], $byId['nod-107']['penaltySince'], $byId['nod-107']['financeState']]);
		self::assertSame(['none', 0.0, '', null], [$byId['nod-206']['penaltyState'], $byId['nod-206']['penaltyEuros'], $byId['nod-206']['penaltySince'], $byId['nod-206']['financeState']]);
	}

	/**
	 * A penalty frozen by an objection or stopped by a decision without a fixed
	 * amount yet still reads as running money, not as paid.
	 *
	 * @return void
	 */
	public function testAPenaltyWithoutAFixedAmountIsNotSentToFinance(): void {
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-206', 'noticeOfDefault' => 'nod-206', 'deadlineInstance' => 'ti-206', 'startDate' => '2026-10-20', 'cumulativeAmount' => 5000, 'status' => 'objection-bevroren']);
		$row = array_column($this->rows->list()['notices'], null, 'id')['nod-206'];

		self::assertSame('running', $row['penaltyState']);
		self::assertSame('amount-open', $row['financeState']);
	}

	/**
	 * Without a notice schema configured there is nothing to list.
	 *
	 * @return void
	 */
	public function testIsEmptyWithoutANoticeSchema(): void {
		$rows = new NoticeOfDefaultRows($this->settings(objects: $this->objects, noticeSchema: ''));

		self::assertSame(['notices' => []], $rows->list());
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
		$this->objects->seed('case', ['id' => 'case-' . $n, 'identifier' => sprintf('2026-%04d', $n), 'title' => $title]);
		$this->objects->seed('deadlineInstance', ['id' => 'ti-' . $n, 'case' => 'case-' . $n]);
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
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'notice_of_default_schema' => $noticeSchema,
				'case_schema' => 'case',
				default => '',
			},
		);

		return $settings;
	}
}
