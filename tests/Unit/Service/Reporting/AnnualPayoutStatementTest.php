<?php

/**
 * The annual statement of the penalty payouts paid in a year.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Reporting
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Service\Reporting\AnnualPayoutStatement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Reporting\AnnualPayoutStatement
 */
class AnnualPayoutStatementTest extends TestCase {

	/**
	 * Settings over a store, with the payout schema configured unless told otherwise.
	 *
	 * @param object $store       The object service.
	 * @param string $payoutSchema The configured payout schema.
	 *
	 * @return SettingsService
	 */
	private function settings(object $store, string $payoutSchema = 'payouts'): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'payout_schema' => $payoutSchema,
				default => '',
			}
		);

		return $settings;
	}//end settings()

	/**
	 * Only the year's payouts are listed, in the statement's own shape, with a warning for a missing payment reference.
	 *
	 * @return void
	 */
	public function testTheYearsPayoutsAreListedWithTotalsAndWarnings(): void {
		$store = new FakeTermijnStore();
		$store->seed('payouts', ['id' => 'u-1', 'reference' => 'REF-1', 'amount' => 35700, 'actualPaymentDate' => '2026-04-20', 'betalingsreferentie' => 'ERP-1', 'status' => 'paid', 'legalBasis' => 'AWB 4:17', 'iban' => 'NL91ABNA0417164300']);
		$store->seed('payouts', ['id' => 'u-2', 'reference' => 'REF-2', 'amount' => 1250, 'actualPaymentDate' => '2026-11-02']);
		$store->seed('payouts', ['id' => 'u-3', 'reference' => 'REF-3', 'amount' => 50000, 'actualPaymentDate' => '2025-12-31', 'betalingsreferentie' => 'ERP-3']);

		$statement = (new AnnualPayoutStatement(settingsService: $this->settings(store: $store)))->forYear(year: 2026);

		$this->assertSame(
			[
				'year' => 2026,
				'jaar' => 2026,
				'rows' => [
					['reference' => 'REF-1', 'bedragCents' => 35700, 'actualPaymentDate' => '2026-04-20', 'betalingsreferentie' => 'ERP-1', 'status' => 'paid', 'legalBasis' => 'AWB 4:17', 'iban' => 'NL91ABNA0417164300'],
					['reference' => 'REF-2', 'bedragCents' => 1250, 'actualPaymentDate' => '2026-11-02', 'betalingsreferentie' => '', 'status' => '', 'legalBasis' => '', 'iban' => ''],
				],
				'summary' => ['count' => 2, 'totalCents' => 36950, 'totalEuros' => 369.5, 'warningCount' => 1],
				'warnings' => ['Missing betalingsreferentie for REF-2'],
			],
			$statement
		);
	}//end testTheYearsPayoutsAreListedWithTotalsAndWarnings()

	/**
	 * Without the payout schema configured, or when the read fails, the statement is empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreGivesTheEmptyStatement(): void {
		$this->assertSame(
			AnnualPayoutStatement::EMPTY_STATEMENT,
			(new AnnualPayoutStatement(settingsService: $this->settings(store: new FakeTermijnStore(), payoutSchema: '')))->forYear(year: 2026)
		);

		$broken = new class {
			/**
			 * Every search fails.
			 *
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return array<mixed>
			 */
			public function searchObjects(mixed ...$arguments): array {
				throw new RuntimeException('store down');
			}

			/**
			 * Every search fails.
			 *
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return array<mixed>
			 */
			public function searchObjectsBySlug(mixed ...$arguments): array {
				throw new RuntimeException('store down');
			}
		};
		$this->assertSame(
			['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0, 'totalEuros' => 0.0, 'warningCount' => 0]],
			(new AnnualPayoutStatement(settingsService: $this->settings(store: $broken)))->forYear(year: 2026)
		);
	}//end testAnUnreadableStoreGivesTheEmptyStatement()
}//end class
