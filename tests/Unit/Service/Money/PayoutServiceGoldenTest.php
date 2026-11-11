<?php

/**
 * Golden tests for PayoutService's refusals and its callback.
 *
 * Pins every refusal of preparing a payout and of a callback with its
 * message and order, the amount fallback, and which callback fields reach
 * the stored row.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\PayoutService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class PayoutServiceGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The store, which fails the reads or writes named in $failing.
	 *
	 * @var FakeTermijnStore
	 */
	private $objects;

	/**
	 * Set up the store with one calculation on a cumulative amount only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class () extends FakeTermijnStore {
			/**
			 * The operations that fail: find, save, search.
			 *
			 * @var array<int, string>
			 */
			public array $failing = [];

			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): \OCA\Dossiq\Tests\Unit\Service\FakeStoredObject {
				if (in_array('find', $this->failing, true) === true) {
					throw new RuntimeException('read down');
				}

				return parent::find($id, $_extend, $files, $register, $schema);
			}

			public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true): \OCA\Dossiq\Tests\Unit\Service\FakeStoredObject {
				if (in_array('save', $this->failing, true) === true) {
					throw new RuntimeException('write down');
				}

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation);
			}

			public function findObjects(string $register, string $schema, array $filters = [], ?string $uuid = null, ?array $slugs = null): array {
				if (in_array('search', $this->failing, true) === true) {
					throw new RuntimeException('search down');
				}

				return parent::findObjects($register, $schema, $filters, $uuid, $slugs);
			}

			public function searchObjects(array $query = []): array {
				if (in_array('search', $this->failing, true) === true) {
					throw new RuntimeException('search down');
				}

				return parent::searchObjects($query);
			}
		};
		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'b-cumulative', 'cumulativeAmount' => 1200]);
	}//end setUp()

	/**
	 * The service over the store.
	 *
	 * @param string $register The configured register, '' for none.
	 *
	 * @return PayoutService
	 */
	private function service(string $register = 'dossiq'): PayoutService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => [
				'register' => $register,
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'payout_schema' => 'dwangsomUitbetaling',
			][$key] ?? ''
		);

		return new PayoutService(settingsService: $settings, dates: $this->caseDates());
	}//end service()

	/**
	 * Run a call and answer the refusal message it raised.
	 *
	 * @param callable $call The call.
	 *
	 * @return string The message.
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		$this->fail('The call must be refused.');
	}//end refusal()

	/**
	 * Every refusal of preparing a payout, by its message.
	 *
	 * @return void
	 */
	public function testThePreparationRefusals(): void {
		$iban = 'NL91ABNA0417164300';
		$this->assertSame('Invalid IBAN provided for dwangsom uitbetaling', $this->refusal(fn () => $this->service(register: '')->preparePayout('b-cumulative', ' ', 'NL00')));
		$this->assertSame('rekeninghouderNaam is required', $this->refusal(fn () => $this->service(register: '')->preparePayout('b-cumulative', ' ', $iban)));
		$this->assertSame('The accruing amount and payout schemas are not configured', $this->refusal(fn () => $this->service(register: '')->preparePayout('b-cumulative', 'J', $iban)));
		$this->assertSame('Accruing amount not found: b-gone', $this->refusal(fn () => $this->service()->preparePayout('b-gone', 'J', $iban)));

		$this->objects->failing = ['find'];
		$this->assertStringStartsWith('Accruing amount lookup failed: ', $this->refusal(fn () => $this->service()->preparePayout('b-cumulative', 'J', $iban)));

		$this->objects->failing = ['save'];
		$this->assertSame('DwangsomUitbetaling persist failed: write down', $this->refusal(fn () => $this->service()->preparePayout('b-cumulative', 'J', $iban)));
	}//end testThePreparationRefusals()

	/**
	 * Without a definitive amount the cumulative one is paid, and the row is stored.
	 *
	 * @return void
	 */
	public function testTheCumulativeAmountIsPaidAndStored(): void {
		$row = $this->service()->preparePayout('b-cumulative', 'J', 'NL91ABNA0417164300', new DateTimeImmutable('2026-04-01'));

		$this->assertSame(1200, $row['amount']);
		$this->assertSame('2026-04-29', $row['paymentDateLatest']);
		$this->assertSame($row['reference'], $this->objects->get('dwangsomUitbetaling', (string)$row['id'])['reference']);
	}//end testTheCumulativeAmountIsPaidAndStored()

	/**
	 * A callback without a payment date or bank reference sets only the status.
	 *
	 * @return void
	 */
	public function testABareCallbackSetsOnlyTheStatus(): void {
		$created = $this->service()->preparePayout('b-cumulative', 'J', 'NL91ABNA0417164300');

		$updated = $this->service()->handleCallback((string)$created['reference'], 'rejected', null);

		$this->assertSame('rejected', $updated['status']);
		$this->assertArrayNotHasKey('actualPaymentDate', $updated);
		$this->assertArrayNotHasKey('betalingsreferentie', $updated);
		$this->assertSame('rejected', $this->objects->get('dwangsomUitbetaling', (string)$created['id'])['status']);
	}//end testABareCallbackSetsOnlyTheStatus()

	/**
	 * Every refusal of a callback, by its message.
	 *
	 * @return void
	 */
	public function testTheCallbackRefusals(): void {
		$created = $this->service()->preparePayout('b-cumulative', 'J', 'NL91ABNA0417164300');

		$this->assertSame('No DwangsomUitbetaling found for referentie UNKNOWN', $this->refusal(fn () => $this->service()->handleCallback('UNKNOWN', 'paid', null)));

		$this->objects->failing = ['save'];
		$this->assertSame('DwangsomUitbetaling persist failed: write down', $this->refusal(fn () => $this->service()->handleCallback((string)$created['reference'], 'paid', null)));

		$this->objects->failing = ['search'];
		$this->assertSame('DwangsomUitbetaling lookup failed: search down', $this->refusal(fn () => $this->service()->handleCallback((string)$created['reference'], 'paid', null)));
	}//end testTheCallbackRefusals()
}//end class
