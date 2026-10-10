<?php

/**
 * A configured cascade writes the target, its dependents and runs a transition.
 *
 * Neutral fixture: a `charge` with two `instalment` rows referencing it, and a
 * `filing` that references a `charge`. The store is a real in-memory register;
 * the transition engine has its own suite and is asserted by its contract.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\OutcomeCascade;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\OutcomeCascade
 */
class OutcomeCascadeTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The transition engine.
	 *
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $transitions;

	/**
	 * The service under test.
	 *
	 * @var OutcomeCascade
	 */
	private OutcomeCascade $cascade;

	/**
	 * Seed a charge with two instalments and a filing against it, and configure three cascades.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'charge', uuid: 'ch-1', row: ['case' => 'case-1', 'status' => 'open', 'amount' => 500]);
		$this->store->seed(schema: 'instalment', uuid: 'in-1', row: ['charge' => 'ch-1', 'status' => 'due']);
		$this->store->seed(schema: 'instalment', uuid: 'in-2', row: ['charge' => 'ch-1', 'status' => 'due']);
		$this->store->seed(schema: 'instalment', uuid: 'in-x', row: ['charge' => 'ch-other', 'status' => 'due']);
		$this->store->seed(schema: 'filing', uuid: 'fi-1', row: ['case' => 'case-2', 'charge' => 'ch-1']);

		$cascades = (string)json_encode(
			[
				'hold'   => [
					'target' => 'charge_schema',
					'steps'  => [
						['set' => ['status' => 'held']],
						['setDependents' => ['schema' => 'instalment_schema', 'field' => 'charge', 'values' => ['status' => 'paused']]],
					],
				],
				'settle' => [
					'target' => 'charge_schema',
					'steps'  => [
						['set' => ['status' => 'settled', 'amount' => ['input' => 'amount']]],
						['setDependents' => ['schema' => 'instalment_schema', 'field' => 'charge', 'values' => ['amount' => ['input' => 'amount']]]],
					],
				],
				'reopen' => [
					'target' => 'filing_schema',
					'steps'  => [
						['set' => ['cascadeAction' => 'reopen']],
						['transition' => 'reopen', 'via' => 'charge', 'viaSchema' => 'charge_schema'],
					],
				],
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register'                   => 'dossiq',
				'charge_schema'              => 'charge',
				'instalment_schema'          => 'instalment',
				'filing_schema'              => 'filing',
				OutcomeCascade::CASCADES_KEY => $cascades,
				default                      => '',
			}
		);

		$this->transitions = $this->createMock(StatusTransitionService::class);
		$this->cascade     = new OutcomeCascade(settingsService: $settings, transitions: $this->transitions);
	}//end setUp()

	/**
	 * The target and only its own dependents take the new status.
	 *
	 * @return void
	 */
	public function testTheTargetAndItsDependentsAreWritten(): void {
		$after = $this->cascade->run(name: 'hold', targetId: 'ch-1');

		self::assertSame('held', $after['status']);
		self::assertSame('held', $this->store->row(schema: 'charge', uuid: 'ch-1')['status']);
		self::assertSame(500, $this->store->row(schema: 'charge', uuid: 'ch-1')['amount']);
		self::assertSame('paused', $this->store->row(schema: 'instalment', uuid: 'in-1')['status']);
		self::assertSame('paused', $this->store->row(schema: 'instalment', uuid: 'in-2')['status']);
		self::assertSame('due', $this->store->row(schema: 'instalment', uuid: 'in-x')['status']);
	}//end testTheTargetAndItsDependentsAreWritten()

	/**
	 * A value can come from the caller.
	 *
	 * @return void
	 */
	public function testAnInputValueIsWritten(): void {
		$this->cascade->run(name: 'settle', targetId: 'ch-1', inputs: ['amount' => 320]);

		self::assertSame(320, $this->store->row(schema: 'charge', uuid: 'ch-1')['amount']);
		self::assertSame(320, $this->store->row(schema: 'instalment', uuid: 'in-2')['amount']);
	}//end testAnInputValueIsWritten()

	/**
	 * A missing input refuses the cascade before anything is written.
	 *
	 * @return void
	 */
	public function testAMissingInputWritesNothing(): void {
		$before = $this->store->writes;

		try {
			$this->cascade->run(name: 'settle', targetId: 'ch-1');
			self::fail('A cascade without its input ran.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString("'amount'", $e->getMessage());
		}

		self::assertSame($before, $this->store->writes);
	}//end testAMissingInputWritesNothing()

	/**
	 * A transition runs on the case of the object the target references.
	 *
	 * @return void
	 */
	public function testATransitionRunsOnTheReferencedCase(): void {
		$this->transitions->expects($this->once())
			->method('execute')
			->with(caseId: 'case-1', transitionId: 'reopen', comment: 'Cascade from fi-1')
			->willReturn([]);

		$this->cascade->run(name: 'reopen', targetId: 'fi-1');

		self::assertSame('reopen', $this->store->row(schema: 'filing', uuid: 'fi-1')['cascadeAction']);
	}//end testATransitionRunsOnTheReferencedCase()

	/**
	 * The case of a target is answered for the caller's access check.
	 *
	 * @return void
	 */
	public function testTheCaseOfTheTarget(): void {
		self::assertSame('case-2', $this->cascade->caseOf(name: 'reopen', targetId: 'fi-1'));
	}//end testTheCaseOfTheTarget()

	/**
	 * An unknown cascade is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownCascadeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("No cascade 'release'");
		$this->cascade->run(name: 'release', targetId: 'ch-1');
	}//end testAnUnknownCascadeIsRefused()
}//end class
