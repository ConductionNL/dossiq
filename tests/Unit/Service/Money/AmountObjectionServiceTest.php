<?php

/**
 * Unit tests for AmountObjectionService.
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

use OCA\Dossiq\Service\Money\AmountObjectionService;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\AmountObjectionService
 *
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Money\PayoutService
 * @uses \OCA\Dossiq\Service\Money\PayoutRequester
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 */
class AmountObjectionServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;
	private SettingsService $settings;
	private AmountObjectionService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'accruing_amount_schema' => 'penaltyPaymentCalculation',
					'payout_schema' => 'dwangsomUitbetaling',
					default => '',
				};
			},
		);

		$this->settings = $settings;
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new AmountObjectionService(
			$settings,
			new TermijnService($settings, $logger),
			$logger
		);

		// Seed berekening + uitbetaling.
		$this->objects->seed('penaltyPaymentCalculation', [
			'id' => 'b-1',
			'deadlineInstance' => 'ti-1',
			'status' => 'gestopt-wegens-decision',
			'definitiveAmount' => 50000,
		]);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'penaltyPaymentCalculation' => 'b-1',
			'amount' => 50000,
			'status' => 'voorbereid',
		]);
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarFreezesBerekeningAndHoldsUitbetaling(): void {
		$b = $this->service->registerObjection('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');
		self::assertSame('objection-bevroren', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame('on-hold-objection', $u['status']);

		// bezwaar-ingediend event recorded.
		$events = array_values($this->objects->store['termijnGebeurtenis'] ?? []);
		self::assertNotEmpty($events);
		self::assertSame('objection-submitted', $events[0]['type']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarAdjustsAmountAndResumes(): void {
		$this->service->registerObjection('b-1', 'AWB 7:1', 'foo');
		$b = $this->service->resolveObjection('b-1', 30000, 'AWB 7:11');

		self::assertSame(30000, $b['definitiveAmount']);
		self::assertSame('completed', $b['status']);

		$u = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame(30000, $u['amount']);
		self::assertSame('voorbereid', $u['status']);
	}

	/**
	 * @return void
	 */
	public function testResolveBezwaarRejectsNegativeAmount(): void {
		$this->expectException(RuntimeException::class);
		$this->service->resolveObjection('b-1', -1, 'AWB 7:11');
	}

	/**
	 * @return void
	 */
	public function testRegisterBezwaarOnUnknownBerekeningFails(): void {
		$this->expectException(RuntimeException::class);
		$this->service->registerObjection('does-not-exist', 'AWB 7:1', 'foo');
	}

	/**
	 * The objection service over a payout service whose finance app answers as told.
	 *
	 * @param string|null $cancelRefusal What finance refuses a withdrawal with, or null to accept.
	 * @param array<int, object> $seen Collects the dispatched commands.
	 *
	 * @return AmountObjectionService The service.
	 */
	private function withFinance(?string $cancelRefusal, array &$seen): AmountObjectionService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($cancelRefusal, &$seen): void {
				$seen[] = $event;
				if ($event instanceof PayableCancelRequestedEvent && $cancelRefusal !== null) {
					$event->refuse($cancelRefusal);
					return;
				}

				$event->accept('p-' . count($seen), 'requested');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$payouts = new PayoutService(
			settingsService: $this->settings,
			dates: $this->caseDates(),
			payouts: new PayoutRequester(dispatcher: $dispatcher),
		);
		$this->objects->seed('dwangsomUitbetaling', [
			'id' => 'u-1',
			'penaltyPaymentCalculation' => 'b-1',
			'amount' => 50000,
			'accountHolderName' => 'J. Burger',
			'iban' => 'NL91ABNA0417164300',
			'reference' => 'PROC-DWS-FIRST',
			'payableId' => 'p-0',
			'status' => 'voorbereid',
		]);

		return new AmountObjectionService($this->settings, new TermijnService($this->settings, $logger), $logger, $payouts);
	}//end withFinance()

	/**
	 * A payout only finance holds is withdrawn by the objection and raised again,
	 * once, with the decided amount; dossiq writes no payout row (REQ-DQM-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function testAPayoutOnlyFinanceHoldsIsWithdrawnAndRaisedAgain(): void {
		unset($this->objects->store['dwangsomUitbetaling']['u-1']);
		$seen = [];
		$finance = [['payableId' => 'p-9', 'reference' => 'PROC-DWS-NINE', 'correlationId' => 'b-1', 'amount' => 500.0, 'state' => 'requested', 'paidOn' => '', 'bankReference' => '', 'payee' => ['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300']]];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$seen, &$finance): void {
				if ($event instanceof PayablesRequestedEvent) {
					$event->answer($finance);
					return;
				}

				$seen[] = $event;
				if ($event instanceof PayableCancelRequestedEvent) {
					$finance[0]['state'] = 'cancelled';
				}

				$event->accept('p-' . (10 + count($seen)), 'requested');
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$requester = new PayoutRequester(dispatcher: $dispatcher);
		$service = new AmountObjectionService(
			$this->settings,
			new TermijnService($this->settings, $logger),
			$logger,
			new PayoutService(settingsService: $this->settings, dates: $this->caseDates(), payouts: $requester),
			new PayoutReader(dispatcher: $dispatcher),
		);

		$service->registerObjection('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');
		self::assertInstanceOf(PayableCancelRequestedEvent::class, $seen[0]);
		self::assertSame('PROC-DWS-NINE', $seen[0]->getReference());

		$service->resolveObjection('b-1', 30000, 'AWB 7:11');
		self::assertCount(2, $seen, 'raised again once');
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[1]);
		self::assertSame(300.0, $seen[1]->toArray()['amount']);
		self::assertSame(['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300'], $seen[1]->toArray()['payee']);
		self::assertSame([], array_values($this->objects->store['dwangsomUitbetaling'] ?? []), 'dossiq writes no payout row');
	}//end testAPayoutOnlyFinanceHoldsIsWithdrawnAndRaisedAgain()

	/**
	 * An objection withdraws a payout from finance under its reference before it
	 * goes on hold; resolving it raises the new amount under a new reference.
	 *
	 * @return void
	 */
	public function testAnObjectionWithdrawsThePayoutFromFinanceAndResolvingRaisesItAgain(): void {
		$seen = [];
		$service = $this->withFinance(cancelRefusal: null, seen: $seen);

		$service->registerObjection('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');
		$held = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertInstanceOf(PayableCancelRequestedEvent::class, $seen[0]);
		self::assertSame('PROC-DWS-FIRST', $seen[0]->getReference());
		self::assertSame('on-hold-objection', $held['status']);
		self::assertSame('', $held['payableId']);

		$service->resolveObjection('b-1', 30000, 'AWB 7:11');
		$resumed = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[1]);
		self::assertSame(300.0, $seen[1]->toArray()['amount']);
		self::assertNotSame('PROC-DWS-FIRST', $resumed['reference']);
		self::assertSame('p-2', $resumed['payableId']);
		self::assertSame('voorbereid', $resumed['status']);
	}//end testAnObjectionWithdrawsThePayoutFromFinanceAndResolvingRaisesItAgain()

	/**
	 * A payout finance already approved stays with finance: it is not put on hold.
	 *
	 * @return void
	 */
	public function testAPayoutFinanceAlreadyApprovedIsNotPutOnHold(): void {
		$seen = [];
		$service = $this->withFinance(cancelRefusal: 'The payable under reference PROC-DWS-FIRST is approved, so only finance can undo it.', seen: $seen);

		$service->registerObjection('b-1', 'AWB 7:1', 'Belanghebbende betwist bedrag');

		$row = $this->objects->store['dwangsomUitbetaling']['u-1'];
		self::assertSame('voorbereid', $row['status']);
		self::assertSame('p-0', $row['payableId']);
	}//end testAPayoutFinanceAlreadyApprovedIsNotPutOnHold()
}
