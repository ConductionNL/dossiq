<?php

/**
 * Unit tests for MigratePayoutRowsToFinance.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigratePayoutRowsToFinance;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every payout row dossiq kept goes to the finance app once, linked to its case.
 */
final class MigratePayoutRowsToFinanceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;

	/**
	 * The payout commands the finance app received.
	 *
	 * @var array<int, PayableRaisedEvent>
	 */
	private array $raised = [];

	/**
	 * The repair over a store with one row in every state.
	 *
	 * @param bool $financeAnswers Whether a finance app answers.
	 *
	 * @return MigratePayoutRowsToFinance
	 */
	private function step(bool $financeAnswers = true): MigratePayoutRowsToFinance {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'payout_schema' => 'dwangsomUitbetaling',
				default => '',
			}
		);
		$base = ['penaltyPaymentCalculation' => 'calc-1', 'amount' => 35700, 'accountHolderName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'legalBasis' => 'AWB 4:17'];
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-prepared', 'reference' => 'DWS-PREP-0001', 'status' => 'voorbereid']);
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-paid', 'reference' => 'DWS-PAID-0001', 'status' => 'paid', 'actualPaymentDate' => '2026-03-03', 'betalingsreferentie' => 'ERP-9']);
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-handling', 'reference' => 'DWS-HAND-0001', 'status' => 'in-handling']);
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-rejected', 'reference' => 'DWS-REJ-0001', 'status' => 'rejected']);
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-objection', 'reference' => 'DWS-OBJ-0001', 'status' => 'on-hold-objection']);
		$this->objects->seed('dwangsomUitbetaling', $base + ['id' => 'u-done', 'reference' => 'DWS-DONE-0001', 'status' => 'paid', 'payableId' => 'payable-old']);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($financeAnswers): void {
				if ($financeAnswers === false) {
					return;
				}

				$this->raised[] = $event;
				$event->accept('payable-' . count($this->raised), ($event->toArray()['historicalState'] ?: 'requested'));
			}
		);
		$owningCase = $this->createMock(OwningCaseResolver::class);
		$owningCase->method('resolveVia')->willReturn('case-7');

		return new MigratePayoutRowsToFinance(
			settingsService: $settings,
			payouts: new PayoutService(settingsService: $settings, dates: $this->caseDates(), payouts: new PayoutRequester(dispatcher: $dispatcher), owningCase: $owningCase),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	public function testEveryRowGoesOverInItsStateLinkedToItsCase(): void {
		$this->step()->run($this->createMock(IOutput::class));

		$byReference = [];
		foreach ($this->raised as $event) {
			$byReference[$event->toArray()['reference']] = $event->toArray();
		}

		self::assertSame(['DWS-PREP-0001', 'DWS-PAID-0001', 'DWS-HAND-0001', 'DWS-REJ-0001'], array_keys($byReference));
		self::assertSame('', $byReference['DWS-PREP-0001']['historicalState'], 'a prepared payout is a live payout now');
		self::assertSame('paid', $byReference['DWS-PAID-0001']['historicalState']);
		self::assertSame('2026-03-03', $byReference['DWS-PAID-0001']['paidOn']);
		self::assertSame('ERP-9', $byReference['DWS-PAID-0001']['bankReference']);
		self::assertSame('approved', $byReference['DWS-HAND-0001']['historicalState']);
		self::assertSame('rejected', $byReference['DWS-REJ-0001']['historicalState']);
		self::assertSame('case-7', $byReference['DWS-PAID-0001']['linkedObjects'][0]['id']);
		self::assertSame(357.0, $byReference['DWS-PAID-0001']['amount']);
	}//end testEveryRowGoesOverInItsStateLinkedToItsCase()

	public function testEachRowKeepsItsPayableAndARunTwiceRaisesNothingNew(): void {
		$step = $this->step();
		$step->run($this->createMock(IOutput::class));

		self::assertSame('payable-2', $this->objects->get('dwangsomUitbetaling', 'u-paid')['payableId'] ?? null);
		self::assertSame('paid', $this->objects->get('dwangsomUitbetaling', 'u-paid')['status'] ?? null, 'the stored row stays readable as it was');
		self::assertArrayNotHasKey('payableId', $this->objects->get('dwangsomUitbetaling', 'u-objection'), 'a row under objection waits for its decision');
		self::assertSame('payable-old', $this->objects->get('dwangsomUitbetaling', 'u-done')['payableId']);

		$count = count($this->raised);
		$step->run($this->createMock(IOutput::class));
		self::assertCount($count, $this->raised);
	}//end testEachRowKeepsItsPayableAndARunTwiceRaisesNothingNew()

	public function testWithoutAFinanceAppNothingMoves(): void {
		$this->step(financeAnswers: false)->run($this->createMock(IOutput::class));

		self::assertArrayNotHasKey('payableId', $this->objects->get('dwangsomUitbetaling', 'u-paid'));
	}//end testWithoutAFinanceAppNothingMoves()
}//end class
