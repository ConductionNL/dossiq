<?php

/**
 * Unit tests for PenaltyPaymentRows: the forfeited penalty payments of a
 * year, each named by its case (board DqTermijnen, "Jaarlijkse dwangsomaudit").
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
 * @spec openspec/specs/termijn-reporting/spec.md#requirement-the-annual-audit-lists-the-forfeited-payments-by-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\PenaltyPaymentRows;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PenaltyPaymentRows.
 */
class PenaltyPaymentRowsTest extends TestCase {
	private FakeTermijnStore $objects;
	private PenaltyPaymentRows $rows;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$this->rows = new PenaltyPaymentRows($this->settings(objects: $this->objects, paymentSchema: 'dwangsomUitbetaling'));

		$this->objects->seed('caseType', ['id' => 'ct-woo', 'title' => 'Woo-verzoek']);
		$this->objects->seed('caseType', ['id' => 'ct-sub', 'title' => 'Subsidie']);
		$this->forfeited(n: 1, caseType: 'ct-woo', requester: 'M. de Graaf', stoppedOn: '2026-03-12', amount: 126000, status: 'paid');
		$this->forfeited(n: 2, caseType: 'ct-sub', requester: 'Buurtvereniging Oost', stoppedOn: '2026-05-28', amount: 216000, status: 'paid');
		$this->forfeited(n: 3, caseType: 'ct-woo', requester: 'Dagblad Drechtsteden', stoppedOn: '2026-09-09', amount: 0, status: 'on-hold-objection');
		$this->forfeited(n: 4, caseType: 'ct-woo', requester: 'Vorig jaar', stoppedOn: '2025-12-30', amount: 50000, status: 'paid');
	}

	/**
	 * A payment names its case, case type, requester and forfeit day; the year
	 * keeps only its own payments, newest forfeit first.
	 *
	 * @return void
	 */
	public function testNamesEachPaymentByItsCase(): void {
		$report = $this->rows->forYear(2026);

		self::assertSame(2026, $report['year']);
		self::assertSame(['2026-0003', '2026-0002', '2026-0001'], array_column($report['rows'], 'caseIdentifier'));

		$first = $report['rows'][2];
		self::assertSame('case-1', $first['caseId']);
		self::assertSame('Case 1', $first['caseTitle']);
		self::assertSame('Woo-verzoek', $first['caseTypeTitle']);
		self::assertSame('M. de Graaf', $first['requester']);
		self::assertSame('2026-03-12', $first['forfeitDate']);
		self::assertSame(1260.0, $first['amountEuros']);
		self::assertSame('paid', $first['paymentStatus']);
	}

	/**
	 * With a finance app the audit reads its payouts, named by their case, beside
	 * the rows finance does not hold (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testReadsThePayoutsFromFinance(): void {
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'pay-1', 'penaltyPaymentCalculation' => 'calc-1', 'amount' => 126000, 'status' => 'paid', 'payableId' => 'payable-1']);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				$event->answer([['payableId' => 'payable-1', 'reference' => 'DWS-1', 'correlationId' => 'calc-1', 'amount' => 999.0, 'state' => 'paid', 'paidOn' => '2026-04-01', 'bankReference' => 'B']]);
			}
		);
		$rows = new PenaltyPaymentRows($this->settings(objects: $this->objects, paymentSchema: 'dwangsomUitbetaling'), new PayoutReader(dispatcher: $dispatcher));

		$report = $rows->forYear(2026);
		$byCase = array_column($report['rows'], null, 'caseIdentifier');

		self::assertSame(999.0, $byCase['2026-0001']['amountEuros'], 'the amount finance holds, not the stored copy');
		self::assertSame('payable-1', $byCase['2026-0001']['id']);
		self::assertArrayHasKey('2026-0002', $byCase, 'a row finance does not hold yet stays');
	}

	/**
	 * The summary counts the year's payments, adds them up and counts the disputed ones.
	 *
	 * @return void
	 */
	public function testSummarisesTheYear(): void {
		$summary = $this->rows->forYear(2026)['summary'];

		self::assertSame(3, $summary['count']);
		self::assertSame(3420.0, $summary['totalEuros']);
		self::assertSame(1, $summary['disputedCount']);
		self::assertSame([['year' => 2026, 'count' => 3, 'totalEuros' => 3420.0, 'disputedCount' => 1]], $this->rows->forYear(2026)['summaryRows']);
	}

	/**
	 * A calculation that has not stopped is dated by its start.
	 *
	 * @return void
	 */
	public function testDatesARunningCalculationByItsStart(): void {
		$this->objects->seed('case', ['id' => 'case-9', 'identifier' => '2026-0009', 'title' => 'Case 9', 'caseType' => 'ct-woo']);
		$this->objects->seed('deadlineInstance', ['id' => 'ti-9', 'case' => 'case-9']);
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-9', 'deadlineInstance' => 'ti-9', 'startDate' => '2026-10-01']);
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'pay-9', 'penaltyPaymentCalculation' => 'calc-9', 'amount' => 2300, 'status' => 'voorbereid']);

		$row = $this->rows->forYear(2026)['rows'][0];

		self::assertSame('2026-0009', $row['caseIdentifier']);
		self::assertSame('2026-10-01', $row['forfeitDate']);
	}

	/**
	 * A payment whose chain is broken still lists, with what is known.
	 *
	 * @return void
	 */
	public function testKeepsAPaymentWhoseCaseIsGone(): void {
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-x', 'deadlineInstance' => 'ti-gone', 'stoppedOn' => '2026-07-01']);
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'pay-x', 'penaltyPaymentCalculation' => 'calc-x', 'amount' => 1000, 'status' => 'paid']);

		$rows = $this->rows->forYear(2026)['rows'];
		$orphan = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] === 'pay-x'))[0];

		self::assertSame('', $orphan['caseIdentifier']);
		self::assertSame('2026-07-01', $orphan['forfeitDate']);
		self::assertSame(10.0, $orphan['amountEuros']);
	}

	/**
	 * Without a payment schema configured there is nothing to list.
	 *
	 * @return void
	 */
	public function testIsEmptyWithoutAPaymentSchema(): void {
		$rows = new PenaltyPaymentRows($this->settings(objects: $this->objects, paymentSchema: ''));

		$none = ['count' => 0, 'totalEuros' => 0.0, 'disputedCount' => 0];
		self::assertSame(['year' => 2026, 'rows' => [], 'summary' => $none, 'summaryRows' => [['year' => 2026] + $none]], $rows->forYear(2026));
	}

	/**
	 * Seed one forfeited payment with its calculation, term and case.
	 *
	 * @param int    $n         The sequence number.
	 * @param string $caseType  The case type id.
	 * @param string $requester The requester's name.
	 * @param string $stoppedOn The day the calculation stopped.
	 * @param int    $amount    The amount in cents.
	 * @param string $status    The payment status.
	 *
	 * @return void
	 */
	private function forfeited(int $n, string $caseType, string $requester, string $stoppedOn, int $amount, string $status): void {
		$this->objects->seed('case', ['id' => 'case-' . $n, 'identifier' => sprintf('2026-%04d', $n), 'title' => 'Case ' . $n, 'caseType' => $caseType, 'initiatorDisplayName' => $requester]);
		$this->objects->seed('deadlineInstance', ['id' => 'ti-' . $n, 'case' => 'case-' . $n]);
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'calc-' . $n, 'deadlineInstance' => 'ti-' . $n, 'startDate' => '2026-01-01', 'stoppedOn' => $stoppedOn]);
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'pay-' . $n, 'penaltyPaymentCalculation' => 'calc-' . $n, 'amount' => $amount, 'status' => $status]);
	}

	/**
	 * Settings that point the schema keys at the fake store's slugs.
	 *
	 * @param FakeTermijnStore $objects       The fake store.
	 * @param string           $paymentSchema The payment schema slug, or '' for none.
	 *
	 * @return SettingsService
	 */
	private function settings(FakeTermijnStore $objects, string $paymentSchema): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'payout_schema' => $paymentSchema,
				default => '',
			},
		);

		return $settings;
	}
}
