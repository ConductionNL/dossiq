<?php

/**
 * AmountObjectionService failure-path tests.
 *
 * An objection moves money that two systems hold. The calculation is dossiq's
 * and its failures are refusals with their own sentence; the payouts are
 * finance's, and a refusal there is logged so the objection itself still runs.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use OCA\Dossiq\Service\Money\AmountObjectionService;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\AmountObjectionService
 * @uses \OCA\Dossiq\Service\Money\ObjectionRun
 * @uses \OCA\Dossiq\Service\Money\PayoutReader
 * @uses \OCA\Dossiq\Service\Money\PayoutRequester
 * @uses \OCA\Dossiq\Service\Money\PayoutService
 * @uses \OCA\Dossiq\Service\Money\MoneyStore
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AmountObjectionServiceFailuresTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * A store whose calls fail on demand, by method and schema.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Settings answering the money schemas and the store.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Set up: a failing-on-demand store with one frozen calculation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new class extends FakeTermijnStore {
			/** @var array<string, string> Method => schema that throws. */
			public array $failOn = [];

			/**
			 * Find, failing when asked.
			 *
			 * @param int|string      $id       The object id.
			 * @param array|null      $_extend  Extends.
			 * @param bool            $files    Files.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema   The schema.
			 *
			 * @return FakeStoredObject The object.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			): FakeStoredObject {
				if (($this->failOn['find'] ?? null) === $schema) {
					throw new RuntimeException('database gone');
				}

				return parent::find(id: $id, _extend: $_extend, files: $files, register: $register, schema: $schema);
			}//end find()

			/**
			 * Save, failing when asked.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param array|null           $extend        Extends.
			 * @param string|int|null      $register      The register.
			 * @param string|int|null      $schema        The schema.
			 * @param string|null          $uuid          The uuid.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 * @param bool                 $silent        Silent.
			 * @param bool                 $_validation   Validation.
			 *
			 * @return FakeStoredObject The saved object.
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
				if (($this->failOn['save'] ?? null) === $schema) {
					throw new RuntimeException('disk full');
				}

				return parent::saveObject($object, $extend, $register, $schema, $uuid, $_rbac, $_multitenancy, $silent, $_validation);
			}//end saveObject()

			/**
			 * Search, failing when asked.
			 *
			 * @param string               $registerSlug The register slug.
			 * @param string               $schemaSlug   The schema slug.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				if (($this->failOn['search'] ?? null) === $schemaSlug) {
					throw new RuntimeException('index unavailable');
				}

				return parent::searchObjectsBySlug($registerSlug, $schemaSlug, $filters);
			}//end searchObjectsBySlug()
		};

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

		$this->objects->seed('penaltyPaymentCalculation', ['id' => 'b-1', 'status' => 'gestopt-wegens-decision', 'definitiveAmount' => 50000]);
	}//end setUp()

	/**
	 * The service under test, with finance wired through the given dispatcher.
	 *
	 * @param LoggerInterface       $logger     The logger.
	 * @param IEventDispatcher|null $dispatcher Finance, or none.
	 *
	 * @return AmountObjectionService The service.
	 */
	private function service(LoggerInterface $logger, ?IEventDispatcher $dispatcher = null): AmountObjectionService {
		if ($dispatcher === null) {
			return new AmountObjectionService($this->settings, new TermijnService($this->settings, $logger), $logger);
		}

		return new AmountObjectionService(
			$this->settings,
			new TermijnService($this->settings, $logger),
			$logger,
			new PayoutService(settingsService: $this->settings, dates: $this->caseDates(), payouts: new PayoutRequester(dispatcher: $dispatcher)),
			new PayoutReader(dispatcher: $dispatcher),
		);
	}//end service()

	/**
	 * A finance app that holds the given payouts and refuses every command.
	 *
	 * @param array<int, array<string, mixed>> $held The payouts finance holds.
	 * @param array<int, object>               $seen The commands finance received.
	 *
	 * @return IEventDispatcher The dispatcher.
	 */
	private function refusingFinance(array $held, array &$seen): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($held, &$seen): void {
				if ($event instanceof PayablesRequestedEvent) {
					$event->answer($held);
					return;
				}

				$seen[] = $event;
				$event->refuse('approved already');
			}
		);

		return $dispatcher;
	}//end refusingFinance()

	/**
	 * One payout as finance holds it.
	 *
	 * @param string $payableId The payable id.
	 * @param string $state     The finance state.
	 *
	 * @return array<string, mixed> The payout.
	 */
	private function held(string $payableId, string $state): array {
		return ['payableId' => $payableId, 'reference' => 'PROC-DWS-' . $payableId, 'correlationId' => 'b-1', 'amount' => 500.0, 'state' => $state, 'payee' => ['name' => 'J. Burger', 'iban' => 'NL91ABNA0417164300']];
	}//end held()

	/**
	 * A lookup that fails is refused with the cause in the sentence.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsRefusedWithItsCause(): void {
		$this->objects->failOn['find'] = 'penaltyPaymentCalculation';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Accruing amount lookup failed: database gone');

		$this->service(logger: $this->createMock(LoggerInterface::class))->registerObjection('b-1', 'AWB 7:1', 'x');
	}//end testAFailedLookupIsRefusedWithItsCause()

	/**
	 * A calculation that cannot be saved is refused, and no payout is touched.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsRefusedAndNoPayoutIsTouched(): void {
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'u-1', 'penaltyPaymentCalculation' => 'b-1', 'amount' => 50000, 'status' => 'voorbereid']);
		$this->objects->failOn['save'] = 'penaltyPaymentCalculation';

		try {
			$this->service(logger: $this->createMock(LoggerInterface::class))->resolveObjection('b-1', 30000, 'AWB 7:11');
			self::fail('a calculation that cannot be saved is refused');
		} catch (RuntimeException $e) {
			self::assertSame('Accruing amount could not be saved: disk full', $e->getMessage());
		}

		self::assertSame(50000, $this->objects->store['dwangsomUitbetaling']['u-1']['amount']);
	}//end testAFailedSaveIsRefusedAndNoPayoutIsTouched()

	/**
	 * A payout search that fails does not block the objection.
	 *
	 * @return void
	 */
	public function testAFailedPayoutSearchDoesNotBlockTheObjection(): void {
		$this->objects->failOn['search'] = 'dwangsomUitbetaling';

		$calculation = $this->service(logger: $this->createMock(LoggerInterface::class))->registerObjection('b-1', 'AWB 7:1', 'x');

		self::assertSame('objection-bevroren', $calculation['status']);
		self::assertSame('objection-bevroren', $this->objects->store['penaltyPaymentCalculation']['b-1']['status']);
	}//end testAFailedPayoutSearchDoesNotBlockTheObjection()

	/**
	 * On resolve, a payout finance refuses again and one that cannot be stored
	 * are each logged; the calculation still completes.
	 *
	 * @return void
	 */
	public function testResolveLogsARefusedRaiseAndAFailedPayoutSave(): void {
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'u-1', 'penaltyPaymentCalculation' => 'b-1', 'amount' => 50000, 'accountHolderName' => 'J. Burger', 'iban' => 'NL91ABNA0417164300', 'reference' => 'PROC-DWS-OLD', 'payableId' => '', 'status' => 'on-hold-objection']);
		$this->objects->failOn['save'] = 'dwangsomUitbetaling';
		$seen = [];

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$calculation = $this->service(logger: $logger, dispatcher: $this->refusingFinance(held: [], seen: $seen))->resolveObjection('b-1', 30000, 'AWB 7:11');

		self::assertSame('completed', $calculation['status']);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[0]);
		self::assertSame(
			[
				['Bezwaar resolve: finance refused the payout again', ['id' => 'u-1', 'error' => 'The finance app refused the payout: approved already']],
				['Bezwaar resolve on uitbetaling failed', ['id' => 'u-1', 'error' => 'disk full']],
			],
			$warnings
		);
	}//end testResolveLogsARefusedRaiseAndAFailedPayoutSave()

	/**
	 * Finance-only payouts: a withdrawn one and one dossiq kept are skipped, and
	 * a refused withdrawal is logged by reference.
	 *
	 * @return void
	 */
	public function testRegisterSkipsWithdrawnAndKeptPayoutsAndLogsARefusedWithdrawal(): void {
		$this->objects->seed('dwangsomUitbetaling', ['id' => 'u-1', 'penaltyPaymentCalculation' => 'b-1', 'amount' => 50000, 'reference' => 'PROC-DWS-p-kept', 'payableId' => 'p-kept', 'status' => 'voorbereid']);
		$held = [$this->held(payableId: 'p-gone', state: 'cancelled'), $this->held(payableId: 'p-kept', state: 'requested'), $this->held(payableId: 'p-live', state: 'approved')];
		$seen = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message, array $context): void {
				if ($message === 'Bezwaar freeze on a payout finance holds failed') {
					self::assertSame(['reference' => 'PROC-DWS-p-live', 'error' => 'The finance app refused the payout: approved already'], $context);
					return;
				}

				self::assertSame('Bezwaar freeze on uitbetaling failed', $message);
				self::assertSame('u-1', $context['id']);
			}
		);

		$this->service(logger: $logger, dispatcher: $this->refusingFinance(held: $held, seen: $seen))->registerObjection('b-1', 'AWB 7:1', 'x');

		$references = array_map(static fn (PayableCancelRequestedEvent $event): string => $event->getReference(), $seen);
		self::assertSame(['PROC-DWS-p-kept', 'PROC-DWS-p-live'], $references);
	}//end testRegisterSkipsWithdrawnAndKeptPayoutsAndLogsARefusedWithdrawal()

	/**
	 * When every finance-only payout is withdrawn, the last one is raised again
	 * with the decided amount; a refusal is logged by reference.
	 *
	 * @return void
	 */
	public function testResolveLogsARefusedRaiseOfTheLastWithdrawnPayout(): void {
		$held = [$this->held(payableId: 'p-1', state: 'cancelled'), $this->held(payableId: 'p-2', state: 'rejected-by-bank')];
		$seen = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Bezwaar resolve: finance refused the payout again',
			['reference' => 'PROC-DWS-p-2', 'error' => 'The finance app refused the payout: approved already'],
		);

		$this->service(logger: $logger, dispatcher: $this->refusingFinance(held: $held, seen: $seen))->resolveObjection('b-1', 30000, 'AWB 7:11');

		self::assertCount(1, $seen);
		self::assertInstanceOf(PayableRaisedEvent::class, $seen[0]);
		self::assertSame(300.0, $seen[0]->toArray()['amount']);
	}//end testResolveLogsARefusedRaiseOfTheLastWithdrawnPayout()
}//end class
