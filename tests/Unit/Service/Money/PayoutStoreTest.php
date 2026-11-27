<?php

/**
 * PayoutStore Unit Tests
 *
 * The payable amount of a calculation (definitive before cumulative, nothing
 * payable refused), the refusal when either schema is not configured, a saved
 * payout row, the row a reference names, and each failing read or write
 * answered with its own sentence and the cause.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\PayoutStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for PayoutStore.
 *
 * @covers \OCA\Dossiq\Service\Money\PayoutStore
 * @uses \OCA\Dossiq\Service\Money\MoneyStore
 */
final class PayoutStoreTest extends TestCase {

	/**
	 * The register the store reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A store over the in-memory register.
	 *
	 * @param array<string, string> $config The configured keys; the defaults name both schemas.
	 *
	 * @return PayoutStore
	 */
	private function store(array $config = []): PayoutStore {
		$this->register = new InMemoryRegister();
		$config = $config + [
			'register' => 'dossiq',
			'accruing_amount_schema' => 'accruingAmount',
			'payout_schema' => 'payout',
		];
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new PayoutStore(settingsService: $settings);
	}//end store()

	/**
	 * The definitive amount is paid; without one, the cumulative amount.
	 *
	 * @return void
	 */
	public function testTheDefinitiveAmountIsPaidBeforeTheCumulativeOne(): void {
		$store = $this->store();
		$this->register->seed(schema: 'accruingAmount', uuid: 'calc-1', row: ['definitiveAmount' => 5000, 'cumulativeAmount' => 9000]);
		$this->register->seed(schema: 'accruingAmount', uuid: 'calc-2', row: ['cumulativeAmount' => 9000]);

		$this->assertSame(expected: 5000, actual: $store->payableAmount(calculationId: 'calc-1'));
		$this->assertSame(expected: 9000, actual: $store->payableAmount(calculationId: 'calc-2'));
	}//end testTheDefinitiveAmountIsPaidBeforeTheCumulativeOne()

	/**
	 * A missing calculation, and one with nothing payable, are refused.
	 *
	 * @return void
	 */
	public function testAMissingOrEmptyCalculationIsRefused(): void {
		$store = $this->store();
		$this->register->seed(schema: 'accruingAmount', uuid: 'calc-0', row: ['cumulativeAmount' => 0]);

		try {
			$store->payableAmount(calculationId: 'nope');
			$this->fail(message: 'A missing calculation must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Accruing amount not found: nope', actual: $e->getMessage());
		}

		$this->expectExceptionMessage(message: 'DwangsomBerekening has no payable amount');
		$store->payableAmount(calculationId: 'calc-0');
	}//end testAMissingOrEmptyCalculationIsRefused()

	/**
	 * Reading the amount checks the payout schema too, before anything is read.
	 *
	 * @return void
	 */
	public function testTheAmountIsRefusedWhenThePayoutSchemaIsNotConfigured(): void {
		$store = $this->store(config: ['payout_schema' => '']);
		$this->register->seed(schema: 'accruingAmount', uuid: 'calc-1', row: ['cumulativeAmount' => 9000]);

		$this->expectExceptionMessage(message: 'The accruing amount and payout schemas are not configured');
		$store->payableAmount(calculationId: 'calc-1');
	}//end testTheAmountIsRefusedWhenThePayoutSchemaIsNotConfigured()

	/**
	 * A failing read of the calculation is answered with the cause.
	 *
	 * @return void
	 */
	public function testAFailingAmountReadNamesTheCause(): void {
		$store = $this->store();
		$this->register->refusedToCaller = ['accruingAmount'];

		$this->expectExceptionMessage(message: "Accruing amount lookup failed: User 'Anonymous' does not have permission to 'read'");
		$store->payableAmount(calculationId: 'calc-1');
	}//end testAFailingAmountReadNamesTheCause()

	/**
	 * A saved row lands in the payout schema and is found again by its reference.
	 *
	 * @return void
	 */
	public function testASavedRowIsFoundByItsReference(): void {
		$store = $this->store();

		$saved = $store->save(row: ['reference' => 'PROC-DWS-1', 'amount' => 5000]);
		$this->assertSame(expected: 'generated-1', actual: $saved['id']);
		$this->assertSame(expected: 1, actual: count($this->register->all(schema: 'payout')));

		$found = $store->byReference(reference: 'PROC-DWS-1');
		$this->assertSame(expected: 5000, actual: $found['amount']);
	}//end testASavedRowIsFoundByItsReference()

	/**
	 * An unknown reference is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownReferenceIsRefused(): void {
		$store = $this->store();

		$this->expectExceptionMessage(message: 'No DwangsomUitbetaling found for referentie PROC-DWS-404');
		$store->byReference(reference: 'PROC-DWS-404');
	}//end testAnUnknownReferenceIsRefused()

	/**
	 * Without a payout schema, neither a save nor a lookup reaches the register.
	 *
	 * @return void
	 */
	public function testWithoutAPayoutSchemaNothingIsWritten(): void {
		$store = $this->store(config: ['payout_schema' => '']);

		try {
			$store->byReference(reference: 'PROC-DWS-1');
			$this->fail(message: 'A lookup without a payout schema must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'The accruing amount and payout schemas are not configured', actual: $e->getMessage());
		}

		$this->expectExceptionMessage(message: 'The accruing amount and payout schemas are not configured');
		try {
			$store->save(row: ['reference' => 'PROC-DWS-1']);
		} finally {
			$this->assertSame(expected: 0, actual: $this->register->writes);
		}
	}//end testWithoutAPayoutSchemaNothingIsWritten()

	/**
	 * A refused save and a failing lookup are each answered with their own sentence and the cause.
	 *
	 * @return void
	 */
	public function testAFailingWriteOrLookupNamesTheCause(): void {
		$store = $this->store();
		$this->register->refusedSchemas = ['payout'];

		try {
			$store->save(row: ['reference' => 'PROC-DWS-1']);
			$this->fail(message: 'A refused save must be answered');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'DwangsomUitbetaling persist failed: Save refused for payout', actual: $e->getMessage());
		}

		$this->register->missingSchemas = ['payout'];
		$this->expectExceptionMessage(message: 'DwangsomUitbetaling lookup failed: No schema payout in this register');
		$store->byReference(reference: 'PROC-DWS-1');
	}//end testAFailingWriteOrLookupNamesTheCause()
}//end class
