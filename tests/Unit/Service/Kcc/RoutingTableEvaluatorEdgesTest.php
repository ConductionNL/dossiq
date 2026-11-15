<?php

/**
 * Edge cases of RoutingTableEvaluator: an injected engine and its refusals, a malformed condition, and a before-only time window.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use DateTimeImmutable;
use OCA\Dossiq\Service\Kcc\RoutingTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RoutingTableEvaluator's engine use and compile edges.
 *
 * @covers \OCA\Dossiq\Service\Kcc\RoutingTableEvaluator
 * @uses \OCA\Dossiq\Service\Kcc\RoutingRuleOrder
 */
class RoutingTableEvaluatorEdgesTest extends TestCase {

	/**
	 * One enabled rule with the given conditions.
	 *
	 * @param array<int, mixed> $conditions The match conditions.
	 *
	 * @return array<string, mixed>
	 */
	private function rule(array $conditions): array {
		return [
			'name' => 'Avond',
			'priority' => 1,
			'enabled' => true,
			'matchConditions' => $conditions,
			'assignedDomain' => 'burgerzaken',
			'assignedTeam' => 'Avonddienst',
			'escalationTeam' => 'Frontoffice',
		];
	}//end rule()

	/**
	 * An injected engine is used, with a FIRST-hit table of the compiled rule and the derived inputs.
	 *
	 * @return void
	 */
	public function testAnInjectedEngineIsUsed(): void {
		$engine = $this->createMock(DecisionTableEvaluator::class);
		$engine->expects(self::once())->method('evaluate')
			->with(
				self::callback(static fn (array $table): bool => $table['hitPolicy'] === 'FIRST'
					&& $table['inputs'] === [['name' => 'minutesOfDay', 'type' => 'number']]
					&& $table['rules'][0]['inputEntries'] === ['< 540']),
				['minutesOfDay' => 480]
			)
			->willReturn(['outputs' => ['rule' => 'Avond', 'assignedTeam' => 'Avonddienst']]);

		$routed = (new RoutingTableEvaluator(engine: $engine))->route(
			[$this->rule([['type' => 'time_of_day', 'value' => 'before_09:00']])],
			[],
			new DateTimeImmutable('2026-06-10T08:00:00')
		);

		self::assertSame(['rule' => 'Avond', 'assignedDomain' => '', 'assignedTeam' => 'Avonddienst', 'escalationTeam' => ''], $routed);
	}//end testAnInjectedEngineIsUsed()

	/**
	 * A before-only window routes before it and not after it.
	 *
	 * @return void
	 */
	public function testABeforeOnlyWindowRoutesBeforeItsBoundary(): void {
		$rules     = [$this->rule([['type' => 'time_of_day', 'value' => 'before_09:00']])];
		$evaluator = new RoutingTableEvaluator();

		self::assertSame('Avonddienst', $evaluator->route($rules, [], new DateTimeImmutable('2026-06-10T08:59:00'))['assignedTeam']);
		self::assertNull($evaluator->route($rules, [], new DateTimeImmutable('2026-06-10T09:00:00')));
	}//end testABeforeOnlyWindowRoutesBeforeItsBoundary()

	/**
	 * A rule with a condition that is not a list of fields is dropped, so nothing routes.
	 *
	 * @return void
	 */
	public function testAMalformedConditionDropsTheRule(): void {
		$engine = $this->createMock(DecisionTableEvaluator::class);
		$engine->expects(self::never())->method('evaluate');

		self::assertNull(
			(new RoutingTableEvaluator(engine: $engine))->route([$this->rule(['keyword'])], ['subject' => 'keyword'], new DateTimeImmutable('2026-06-10T08:00:00'))
		);
	}//end testAMalformedConditionDropsTheRule()

	/**
	 * No matching rule is no route; any other engine refusal reaches the caller.
	 *
	 * @return void
	 */
	public function testOnlyANoMatchRefusalIsNoRoute(): void {
		$rules = [$this->rule([['type' => 'keyword', 'value' => 'paspoort']])];

		$noMatch = $this->createMock(DecisionTableEvaluator::class);
		$noMatch->method('evaluate')->willThrowException(new DecisionEvaluationException(errorCode: 'no_rule_matched'));
		self::assertNull((new RoutingTableEvaluator(engine: $noMatch))->route($rules, [], new DateTimeImmutable('2026-06-10T08:00:00')));

		$broken = $this->createMock(DecisionTableEvaluator::class);
		$broken->method('evaluate')->willThrowException(new DecisionEvaluationException(errorCode: 'invalid_table'));
		$this->expectException(DecisionEvaluationException::class);
		$this->expectExceptionMessage('invalid_table');
		(new RoutingTableEvaluator(engine: $broken))->route($rules, [], new DateTimeImmutable('2026-06-10T08:00:00'));
	}//end testOnlyANoMatchRefusalIsNoRoute()
}//end class
