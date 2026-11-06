<?php

/**
 * Unit tests for PayoutService.
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
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\PayoutService
 * @covers \OCA\Dossiq\Service\Money\PayoutRequester
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class PayoutServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;
	private PayoutService $service;

	private SettingsService $settings;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'accruing_amount_schema' => 'penaltyPaymentCalculation',
					'payout_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->settings = $settings;
		$this->service = new PayoutService(settingsService: $settings, dates: $this->caseDates());

		// Seed a stopped berekening.
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-stopped',
			'noticeOfDefault' => 'ig-1',
			'deadlineInstance' => 'ti-1',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 35700,
		]);
	}

	/**
	 * @return void
	 */
	public function testValidIbanPassesValidation(): void {
		// Real test IBANs (mod-97 checksum verified).
		self::assertTrue($this->service->isValidIban('NL91ABNA0417164300'));
		self::assertTrue($this->service->isValidIban('NL91 ABNA 0417 1643 00'));
		self::assertTrue($this->service->isValidIban('DE89370400440532013000'));
	}

	/**
	 * @return void
	 */
	public function testInvalidIbanFailsValidation(): void {
		self::assertFalse($this->service->isValidIban(''));
		self::assertFalse($this->service->isValidIban('NL91ABNA1234567890'));
		self::assertFalse($this->service->isValidIban('not-an-iban'));
	}

	/**
	 * @return void
	 */
	public function testPreparePayoutCreatesUitbetaling(): void {
		$row = $this->service->preparePayout(
			'b-stopped',
			'J. Burger',
			'NL91ABNA0417164300',
			new DateTimeImmutable('2026-04-01')
		);

		self::assertSame(35700, $row['amount']);
		self::assertSame('voorbereid', $row['status']);
		self::assertSame('NL91ABNA0417164300', $row['iban']);
		self::assertSame('2026-04-29', $row['paymentDateLatest']);
		self::assertSame('AWB 4:17', $row['legalBasis']);
		self::assertStringStartsWith('PROC-DWS-', $row['reference']);
	}

	/**
	 * @return void
	 */
	public function testPreparePayoutRejectsInvalidIban(): void {
		$this->expectException(RuntimeException::class);
		$this->service->preparePayout('b-stopped', 'J. Burger', 'INVALID');
	}

	/**
	 * @return void
	 */
	public function testPreparePayoutRejectsZeroAmount(): void {
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-zero',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 0,
			'cumulativeAmount' => 0,
		]);

		$this->expectException(RuntimeException::class);
		$this->service->preparePayout('b-zero', 'J. Burger', 'NL91ABNA0417164300');
	}

	/**
	 * @return void
	 */
	public function testHandleCallbackUpdatesUitbetaling(): void {
		$created = $this->service->preparePayout(
			'b-stopped',
			'J. Burger',
			'NL91ABNA0417164300',
			new DateTimeImmutable('2026-04-01')
		);

		$updated = $this->service->handleCallback(
			(string)$created['reference'],
			'paid',
			new DateTimeImmutable('2026-04-20'),
			'ERP-XYZ-987'
		);

		self::assertSame('paid', $updated['status']);
		self::assertSame('2026-04-20', $updated['actualPaymentDate']);
		self::assertSame('ERP-XYZ-987', $updated['betalingsreferentie']);
	}

	/**
	 * @return void
	 */
	public function testHandleCallbackRejectsUnknownReferentie(): void {
		$this->expectException(RuntimeException::class);
		$this->service->handleCallback('UNKNOWN-REF', 'paid', new DateTimeImmutable());
	}

	/**
	 * The payout service over a dispatcher whose finance app answers as told.
	 *
	 * @param string|null $refusal What finance refuses with, or null to accept.
	 * @param array<int, object> $seen Collects the dispatched commands.
	 *
	 * @return PayoutService The service.
	 */
	private function withFinance(?string $refusal, array &$seen, ?OwningCaseResolver $owningCase = null): PayoutService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($refusal, &$seen): void {
				$seen[] = $event;
				if ($refusal !== null) {
					$event->refuse($refusal);
					return;
				}

				$state = 'requested';
				if ($event instanceof PayableCancelRequestedEvent) {
					$state = 'cancelled';
				}

				$event->accept('payable-' . count($seen), $state);
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnMap(
			[[PayoutService::CALLBACK_ROUTE, [], 'https://gemeente.example/apps/dossiq/api/payments/callback']]
		);

		return new PayoutService(
			settingsService: $this->settings,
			dates: $this->caseDates(),
			payouts: new PayoutRequester(dispatcher: $dispatcher),
			urls: $urls,
			owningCase: $owningCase,
		);
	}//end withFinance()

	/**
	 * A prepared payout is raised in the finance app with the amount in euros,
	 * the payee, the reference, the calculation and the callback address, and
	 * the row keeps the payable id.
	 *
	 * @return void
	 */
	public function testPreparePayoutRaisesItInFinance(): void {
		$seen = [];
		$row = $this->withFinance(refusal: null, seen: $seen)->preparePayout(
			'b-stopped',
			'J. Burger',
			'NL91 ABNA 0417 1643 00',
			new DateTimeImmutable('2026-04-01')
		);

		self::assertCount(1, $seen);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[0]);
		$command = $seen[0]->toArray();
		self::assertSame('dossiq', $command['sourceApp']);
		self::assertSame(357.0, $command['amount']);
		self::assertSame(['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300'], $command['payee']);
		self::assertSame($row['reference'], $command['reference']);
		self::assertSame('b-stopped', $command['correlationId']);
		self::assertSame(PayoutService::CATEGORY, $command['category']);
		self::assertSame('2026-04-29', $command['dueOn']);
		self::assertStringEndsWith('/api/payments/callback', $command['callbackUrl']);
		self::assertSame('payable-1', $row['payableId']);
		self::assertSame('voorbereid', $row['status']);
		self::assertSame([], array_values($this->objects->store['dwangsomUitbetaling'] ?? []), 'finance holds the payout; dossiq writes no row (REQ-DQM-004)');
	}//end testPreparePayoutRaisesItInFinance()

	/**
	 * The payout names its case, so it shows in the case's financial documents (REQ-DQM-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-002)
	 */
	public function testThePayoutNamesItsCase(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn('case-7');
		$seen = [];

		$this->withFinance(refusal: null, seen: $seen, owningCase: $owningCase)->preparePayout('b-stopped', 'J. Burger', 'NL91 ABNA 0417 1643 00', new DateTimeImmutable('2026-04-01'));

		self::assertSame(
			[['app' => 'dossiq', 'type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => 'case-7']],
			$seen[0]->toArray()['linkedObjects']
		);
	}//end testThePayoutNamesItsCase()

	/**
	 * A payout whose case cannot be resolved still reaches finance, linked to nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-002)
	 */
	public function testAPayoutWithoutACaseIsStillRaised(): void {
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn(null);
		$seen = [];

		$this->withFinance(refusal: null, seen: $seen, owningCase: $owningCase)->preparePayout('b-stopped', 'J. Burger', 'NL91 ABNA 0417 1643 00', new DateTimeImmutable('2026-04-01'));

		self::assertSame([], $seen[0]->toArray()['linkedObjects']);
	}//end testAPayoutWithoutACaseIsStillRaised()

	/**
	 * When finance refuses the payout nothing is written.
	 *
	 * @return void
	 */
	public function testARefusedPayoutWritesNothing(): void {
		$seen = [];
		$before = count($this->objects->store['dwangsomUitbetaling'] ?? []);
		try {
			$this->withFinance(refusal: 'The payee\'s IBAN is not valid.', seen: $seen)->preparePayout('b-stopped', 'J. Burger', 'NL91ABNA0417164300');
			self::fail('a refused payout was written');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('IBAN is not valid', $e->getMessage());
		}

		self::assertSame($before, count($this->objects->store['dwangsomUitbetaling'] ?? []));
	}//end testARefusedPayoutWritesNothing()

	/**
	 * Withdrawing sends the cancel command under the row's reference and clears
	 * the payable id; raising again uses a new reference and the new amount. A
	 * row that never reached finance is left alone.
	 *
	 * @return void
	 */
	public function testWithdrawAndRaiseAgain(): void {
		$seen = [];
		$service = $this->withFinance(refusal: null, seen: $seen);
		$row = $service->preparePayout('b-stopped', 'J. Burger', 'NL91ABNA0417164300');

		$held = $service->withdrawFromFinance(row: $row, reason: 'Bezwaar ingediend');
		self::assertInstanceOf(PayableCancelRequestedEvent::class, $seen[1]);
		self::assertSame($row['reference'], $seen[1]->getReference());
		self::assertSame('Bezwaar ingediend', $seen[1]->getReason());
		self::assertSame('', $held['payableId']);

		$held['amount'] = 20000;
		$again = $service->raiseAgain(row: $held);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[2]);
		self::assertSame(200.0, $seen[2]->toArray()['amount']);
		self::assertNotSame($row['reference'], $again['reference']);
		self::assertSame('payable-3', $again['payableId']);

		$untouched = ['reference' => 'PROC-DWS-OLD', 'amount' => 100];
		self::assertSame($untouched, $service->withdrawFromFinance(row: $untouched, reason: 'x'));
		self::assertCount(3, $seen);
	}//end testWithdrawAndRaiseAgain()

	/**
	 * Finance refusing to withdraw (it already approved) surfaces as an error.
	 *
	 * @return void
	 */
	public function testAWithdrawalFinanceRefusesThrows(): void {
		$seen = [];
		$service = $this->withFinance(refusal: 'The payable under reference X is approved, so only finance can undo it.', seen: $seen);

		$this->expectException(RuntimeException::class);
		$service->withdrawFromFinance(row: ['reference' => 'PROC-DWS-1', 'payableId' => 'p-1'], reason: 'Bezwaar');
	}//end testAWithdrawalFinanceRefusesThrows()
}
