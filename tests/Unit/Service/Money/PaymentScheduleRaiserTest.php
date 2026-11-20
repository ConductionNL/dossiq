<?php

/**
 * Unit tests for PaymentScheduleRaiser.
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
use OCA\Dossiq\Service\Money\PaymentScheduleRaiser;
use OCA\Dossiq\Service\Money\PayoutRequester;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Money\PaymentScheduleRaiser
 * @uses \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
class PaymentScheduleRaiserTest extends TestCase {

	/**
	 * Fake ObjectService: serves decision pages and records saves.
	 *
	 * @var object
	 */
	private object $objects;

	/** @var PayoutRequester&MockObject */
	private PayoutRequester $payouts;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private PaymentScheduleRaiser $raiser;

	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<int, mixed> */
			public array $rows = [];

			/** @var list<array<string, mixed>> */
			public array $findCalls = [];

			/** @var list<array{object: array<string, mixed>, options: ObjectSaveOptions}> */
			public array $saves = [];

			public function findAll(array $config): array {
				$this->findCalls[] = $config;
				return array_slice($this->rows, (int)$config['offset'], (int)$config['limit']);
			}

			public function saveObject(array $object, ObjectSaveOptions $options): array {
				$this->saves[] = ['object' => $object, 'options' => $options];
				return $object;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);

		$this->payouts = $this->createMock(PayoutRequester::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->raiser  = new PaymentScheduleRaiser(settings: $settings, payouts: $this->payouts, logger: $this->logger);
	}

	/**
	 * A decision with the given instalments.
	 *
	 * @param list<array<string, mixed>> $instalments The instalments.
	 * @param string                     $id          The decision id.
	 *
	 * @return array<string, mixed>
	 */
	private function decision(array $instalments, string $id = 'd-1'): array {
		return [
			'id' => $id,
			'reference' => 'BES-' . $id,
			'caseId' => 'case-' . $id,
			'addressee' => ['name' => 'Stichting Voorbeeld'],
			'paymentSchedule' => ['payeeIban' => 'NL91ABNA0417164300', 'instalments' => $instalments],
		];
	}

	public function testReturnsZeroWithoutAnObjectService(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$this->payouts->expects($this->never())->method('request');

		$raiser = new PaymentScheduleRaiser(settings: $settings, payouts: $this->payouts, logger: $this->logger);

		$this->assertSame(0, $raiser->raiseDue(new DateTimeImmutable('2026-05-01')));
	}

	public function testRaisesADueInstalmentWithTheFinancePayloadAndSavesItsPayableId(): void {
		$this->objects->rows = [
			$this->decision([
				['dueOn' => '2026-04-30T00:00:00Z', 'amountCents' => 125000, 'label' => 'Voorschot 1'],
			]),
		];

		$this->payouts->expects($this->once())
			->method('request')
			->with([
				'amountCents' => 125000,
				'payeeName' => 'Stichting Voorbeeld',
				'iban' => 'NL91ABNA0417164300',
				'reference' => 'BES-d-1-1',
				'description' => 'Voorschot 1',
				'correlationId' => 'd-1',
				'dueOn' => '2026-04-30',
				'linkedObjects' => ['d-1', 'case-d-1'],
			])
			->willReturn(['payableId' => 'pay-9', 'state' => 'open']);

		$this->assertSame(1, $this->raiser->raiseDue(new DateTimeImmutable('2026-05-01')));

		$this->assertCount(1, $this->objects->saves);
		$saved = $this->objects->saves[0];
		$this->assertSame('pay-9', $saved['object']['paymentSchedule']['instalments'][0]['payableId']);
		$this->assertSame('2026-05-01', $saved['object']['paymentSchedule']['instalments'][0]['raisedAt']);
		$this->assertSame('dossiq', $saved['options']->target->register);
		$this->assertSame(PaymentScheduleRaiser::DECISION_SCHEMA, $saved['options']->target->schema);
		$this->assertSame('d-1', $saved['options']->uuid);
		$this->assertSame(
			['filters' => ['register' => 'dossiq', 'schema' => 'beschikking'], 'limit' => 200, 'offset' => 0],
			$this->objects->findCalls[0]
		);
	}

	public function testAnInstalmentDueTodayIsRaised(): void {
		$this->payouts->expects($this->once())->method('request')->willReturn(['payableId' => 'p', 'state' => 'open']);

		$raised = $this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: $this->decision([['dueOn' => '2026-05-01', 'amountCents' => 1]]),
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame(1, $raised);
	}

	public function testSkipsInstalmentsThatAreNotDueRaisedEmptyOrConditional(): void {
		$this->payouts->expects($this->never())->method('request');

		$raised = $this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: $this->decision([
				['dueOn' => '2026-05-02', 'amountCents' => 100],
				['dueOn' => '', 'amountCents' => 100],
				['dueOn' => '2026-01-01', 'amountCents' => 100, 'payableId' => 'already'],
				['dueOn' => '2026-01-01', 'amountCents' => 0],
				['dueOn' => '2026-01-01', 'amountCents' => 100, 'condition' => 'report approved'],
				['dueOn' => '2026-01-01', 'amountCents' => 100, 'condition' => 'report approved', 'conditionMet' => 'yes'],
			]),
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame(0, $raised);
		$this->assertSame([], $this->objects->saves);
	}

	public function testAMetConditionLetsTheInstalmentBeRaisedWithItsPosition(): void {
		$this->payouts->expects($this->once())
			->method('request')
			->with($this->callback(static fn (array $p): bool => $p['reference'] === 'BES-d-1-2' && $p['amountCents'] === 500))
			->willReturn(['payableId' => 'pay-2', 'state' => 'open']);

		$raised = $this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: $this->decision([
				['dueOn' => '2026-01-01', 'amountCents' => 100, 'payableId' => 'pay-1'],
				['dueOn' => '2026-01-01', 'amountCents' => 500, 'condition' => 'report approved', 'conditionMet' => true],
			]),
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame(1, $raised);
		$instalments = $this->objects->saves[0]['object']['paymentSchedule']['instalments'];
		$this->assertSame('pay-1', $instalments[0]['payableId']);
		$this->assertArrayNotHasKey('raisedAt', $instalments[0]);
		$this->assertSame('pay-2', $instalments[1]['payableId']);
	}

	public function testARefusedInstalmentIsLoggedAndStaysOpenWhileOthersAreRaised(): void {
		$this->payouts->expects($this->exactly(2))
			->method('request')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('iban rejected')),
				['payableId' => 'pay-b', 'state' => 'open'],
			);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq: the finance app refused an instalment; it stays open for the next run',
				['decision' => 'd-1', 'instalment' => 0, 'exception' => 'iban rejected']
			);

		$raised = $this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: $this->decision([
				['dueOn' => '2026-01-01', 'amountCents' => 100],
				['dueOn' => '2026-01-02', 'amountCents' => 200],
			]),
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame(1, $raised);
		$instalments = $this->objects->saves[0]['object']['paymentSchedule']['instalments'];
		$this->assertArrayNotHasKey('payableId', $instalments[0]);
		$this->assertSame('pay-b', $instalments[1]['payableId']);
	}

	public function testNothingIsSavedWhenFinanceRaisesNothing(): void {
		$this->payouts->method('request')->willReturn(null);

		$raised = $this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: $this->decision([['dueOn' => '2026-01-01', 'amountCents' => 100]]),
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame(0, $raised);
		$this->assertSame([], $this->objects->saves);
	}

	public function testFallsBackToUuidAndIdWhenTheDecisionHasNoReference(): void {
		$this->payouts->expects($this->once())
			->method('request')
			->with($this->callback(
				static fn (array $p): bool => $p['reference'] === 'u-7-1'
					&& $p['correlationId'] === 'u-7'
					&& $p['payeeName'] === ''
					&& $p['iban'] === ''
					&& $p['linkedObjects'] === ['u-7', '']
			))
			->willReturn(['payableId' => 'p', 'state' => 'open']);

		$this->raiser->raiseForDecision(
			objectService: $this->objects,
			decision: ['uuid' => 'u-7', 'paymentSchedule' => ['instalments' => [['dueOn' => '2026-01-01', 'amountCents' => 1]]]],
			today: new DateTimeImmutable('2026-05-01'),
		);

		$this->assertSame('u-7', $this->objects->saves[0]['options']->uuid);
	}

	public function testPagesThroughEveryDecisionAndSkipsThoseWithoutInstalments(): void {
		$rows = [];
		for ($i = 0; $i < 200; $i++) {
			$rows[] = ['id' => 'empty-' . $i, 'paymentSchedule' => ['instalments' => []]];
		}

		$rows[] = $this->decision([['dueOn' => '2026-01-01', 'amountCents' => 100]], 'd-201');
		$rows[] = ['id' => 'no-schedule'];
		$this->objects->rows = $rows;

		$this->payouts->expects($this->once())->method('request')->willReturn(['payableId' => 'p', 'state' => 'open']);

		$this->assertSame(1, $this->raiser->raiseDue(new DateTimeImmutable('2026-05-01')));
		$this->assertSame([0, 200], array_column($this->objects->findCalls, 'offset'));
		$this->assertSame('d-201', $this->objects->saves[0]['options']->uuid);
	}

	public function testAFullLastPageTriggersOneMoreEmptyRead(): void {
		$this->objects->rows = array_fill(0, 200, ['id' => 'x']);
		$this->payouts->expects($this->never())->method('request');

		$this->assertSame(0, $this->raiser->raiseDue(new DateTimeImmutable('2026-05-01')));
		$this->assertSame([0, 200], array_column($this->objects->findCalls, 'offset'));
	}
}
