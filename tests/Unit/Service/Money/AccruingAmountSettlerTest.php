<?php

/**
 * Unit tests for AccruingAmountSettler on a neutral late-fee fixture.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\AccruingAmountSettler;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../Fixtures/FakeTermijnStore.php';

/**
 * A late fee of 100 cents a day accrues from startDate; the store plays
 * OpenRegister's save-time materialisation of the stored calculation.
 *
 * @spec openspec/changes/penalty-is-a-stored-calculation/specs/accruing-amount/spec.md
 */
final class AccruingAmountSettlerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The store.
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Build the settler over a store whose save materialises `accrued`.
	 *
	 * @param string $schemaKey The configured schema key.
	 *
	 * @return AccruingAmountSettler
	 */
	private function settler(string $schemaKey = 'late_fee_schema'): AccruingAmountSettler {
		$this->objects = new class extends FakeTermijnStore {
			/**
			 * Save, re-materialising the accrued amount the way OpenRegister does.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param array|null $extend Unused.
			 * @param string|int|null $register Register.
			 * @param string|int|null $schema Schema.
			 * @param string|null $uuid Uuid.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 * @param bool $silent Unused.
			 * @param bool $_validation Unused.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
			): FakeStoredObject {
				$until = new DateTimeImmutable((string)($object['stoppedOn'] ?? 'today'));
				$days = max(0, (int)(new DateTimeImmutable((string)$object['startDate']))->diff($until)->format('%r%a'));
				$object['accrued'] = $days * 100;
				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation);
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'r',
				'late_fee_schema' => 'lateFee',
				default => '',
			}
		);

		return new AccruingAmountSettler(settingsService: $settings, dates: $this->caseDates());
	}//end settler()

	/**
	 * Settling stops the clock on the given day, sets the status and locks
	 * the amount OpenRegister materialised up to that day.
	 *
	 * @return void
	 */
	public function testSettleStopsTheClockAndLocksTheMaterialisedAmount(): void {
		$settler = $this->settler();
		$this->objects->seed('lateFee', ['id' => 'f1', 'startDate' => '2026-03-01', 'status' => 'running']);

		$row = $settler->settle(
			schemaConfigKey: 'late_fee_schema',
			id: 'f1',
			status: 'stopped',
			stopAt: new DateTimeImmutable('2026-03-08 15:00'),
			amountField: 'accrued',
			lockField: 'finalAmount',
		);

		self::assertSame('2026-03-08', $row['stoppedOn']);
		self::assertSame('stopped', $row['status']);
		self::assertSame(700, $row['finalAmount']);
		self::assertSame(700, $this->objects->store['lateFee']['f1']['finalAmount']);
	}//end testSettleStopsTheClockAndLocksTheMaterialisedAmount()

	/**
	 * A row that already stopped keeps its stop day: a later settle (an
	 * objection, then a decision) does not let the amount run on.
	 *
	 * @return void
	 */
	public function testAnEarlierStopDayIsKept(): void {
		$settler = $this->settler();
		$this->objects->seed('lateFee', ['id' => 'f1', 'startDate' => '2026-03-01', 'stoppedOn' => '2026-03-04', 'status' => 'frozen']);

		$row = $settler->settle(schemaConfigKey: 'late_fee_schema', id: 'f1', status: 'stopped', stopAt: new DateTimeImmutable('2026-03-20'), amountField: 'accrued', lockField: 'finalAmount');

		self::assertSame('2026-03-04', $row['stoppedOn']);
		self::assertSame(300, $row['finalAmount']);
	}//end testAnEarlierStopDayIsKept()

	/**
	 * An unknown id answers null; an unconfigured store is an error, never a silent zero.
	 *
	 * @return void
	 */
	public function testMissingRowIsNullAndMissingStoreIsAnError(): void {
		$settler = $this->settler();
		self::assertNull($settler->settle(schemaConfigKey: 'late_fee_schema', id: 'nope', status: 'stopped'));

		$this->expectException(RuntimeException::class);
		$settler->settle(schemaConfigKey: 'unconfigured_schema', id: 'f1', status: 'stopped');
	}//end testMissingRowIsNullAndMissingStoreIsAnError()
}//end class
