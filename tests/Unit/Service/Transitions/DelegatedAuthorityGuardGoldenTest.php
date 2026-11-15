<?php

/**
 * Characterisation tests for DelegatedAuthorityGuard::evaluate: which act,
 * actor and amount it asks decidiq about, and the verdict, sentence and
 * details of every answer, the allowed one and each refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/delegated-authority-guard/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\DelegatedAuthorityGuard;
use OCA\Dossiq\Service\Transitions\GuardResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

class DelegatedAuthorityGuardGoldenTest extends TestCase {

	/**
	 * The questions decidiq was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * Every line logged, as [level, message, context].
	 *
	 * @var array<int, array>
	 */
	private array $lines = [];

	/**
	 * A guard over decidiq answering $answer; absent when $installed is false.
	 *
	 * @param mixed $answer    What check() answers; a Throwable is thrown.
	 * @param bool  $installed Whether decidiq is installed.
	 *
	 * @return DelegatedAuthorityGuard The guard.
	 */
	private function guard(mixed $answer, bool $installed = true): DelegatedAuthorityGuard {
		$asked = &$this->asked;
		$check = new class($answer, $asked) {
			public function __construct(private mixed $answer, private array &$asked) {
			}

			public function check(string $actor, string $act, ?float $amount = null): mixed {
				$this->asked[] = ['actor' => $actor, 'act' => $act, 'amount' => $amount];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($installed, $check): object {
				if ($installed === false || $id !== 'OCA\\Decidiq\\Service\\DelegatedAuthorityCheck') {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $check;
			}
		);

		$sink = &$this->lines;
		$logger = new class($sink) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = [(string)$level, (string)$message, $context];
			}
		};

		return new DelegatedAuthorityGuard($container, $logger, l10n: new SourceL10n());
	}//end guard()

	/**
	 * A verdict as one comparable row.
	 *
	 * @param GuardResult $result The verdict.
	 *
	 * @return array The row.
	 */
	private function row(GuardResult $result): array {
		return [$result->passed, $result->failureMessage, $result->details];
	}//end row()

	public function testAnAuthorisedAnswerPassesWithItsAllocation(): void {
		$result = $this->guard(['authorised' => true, 'allocation' => 'mandaat-7'])->evaluate(
			['act' => ' sign ', 'amountField' => 'amountCents'],
			['signatory' => 'alice', 'properties' => [['name' => 'amountCents', 'value' => '1250050']]],
			'me'
		);

		self::assertSame([true, null, ['authority' => 'confirmed', 'allocation' => 'mandaat-7']], $this->row($result));
		self::assertSame([['actor' => 'alice', 'act' => 'sign', 'amount' => 12500.5]], $this->asked);
	}//end testAnAuthorisedAnswerPassesWithItsAllocation()

	public function testTheActComesFromTheCaseWhenTheConfigNamesAField(): void {
		$guard = $this->guard(['authorised' => true]);
		$guard->evaluate(['actField' => 'letterKind'], ['letterKind' => 'kind-a', 'signatory' => 'bob'], 'me');
		$guard->evaluate(['act' => 'given', 'actField' => 'letterKind'], ['letterKind' => 'kind-a'], 'me');
		$guard->withDefaults(['actField' => 'letterKind', 'actorField' => 'owner'])->evaluate([], ['letterKind' => 'kind-b', 'owner' => 'carol'], 'me');

		self::assertSame(
			[
				['actor' => 'bob', 'act' => 'kind-a', 'amount' => null],
				['actor' => 'me', 'act' => 'given', 'amount' => null],
				['actor' => 'carol', 'act' => 'kind-b', 'amount' => null],
			],
			$this->asked
		);
	}//end testTheActComesFromTheCaseWhenTheConfigNamesAField()

	public function testNoActRefusesWithoutAsking(): void {
		$result = $this->guard(['authorised' => true])->evaluate(['actField' => 'missing'], [], 'me');

		self::assertSame([false, 'This step names no act to check the mandate for.', ['authority' => 'no-act']], $this->row($result));
		self::assertSame([], $this->asked);
	}//end testNoActRefusesWithoutAsking()

	public function testAnUnreadableAmountRefusesWithoutAsking(): void {
		$result = $this->guard(['authorised' => true])->evaluate(['act' => 'a', 'amountField' => 'amount'], ['amount' => 'lots'], 'me');

		self::assertSame(
			[false, 'The amount on the case is not a number, so the mandate cannot be checked.', ['authority' => 'amount-unreadable']],
			$this->row($result)
		);
		self::assertSame([], $this->asked);
	}//end testAnUnreadableAmountRefusesWithoutAsking()

	public function testAMissingAmountAsksWithoutOne(): void {
		$this->guard(['authorised' => true])->evaluate(['act' => 'a', 'amountField' => 'amount'], ['properties' => [['name' => 'amount', 'value' => '']]], 'me');
		$this->guard(['authorised' => true])->evaluate(['act' => 'a', 'amountField' => ' '], ['amount' => 'lots'], 'me');

		self::assertSame([null, null], array_column($this->asked, 'amount'));
	}//end testAMissingAmountAsksWithoutOne()

	public function testAnAbsentRegisterRefuses(): void {
		$result = $this->guard(null, installed: false)->evaluate(['act' => 'a'], [], 'me');

		self::assertSame(
			[false, 'The mandate register (decidiq) is not installed, so the mandate cannot be checked.', ['authority' => 'register-absent']],
			$this->row($result)
		);
	}//end testAnAbsentRegisterRefuses()

	public function testAFailingRegisterIsLoggedAndRefuses(): void {
		$result = $this->guard(new RuntimeException('decidiq broke'))->evaluate(['act' => 'a'], [], 'me');

		self::assertSame(
			[false, 'The mandate register cannot be read right now, so this step cannot be approved.', ['authority' => 'register-unreadable']],
			$this->row($result)
		);
		self::assertSame(
			[['warning', 'Dossiq could not ask decidiq for delegated authority: decidiq broke', ['app' => 'dossiq', 'act' => 'a']]],
			$this->lines
		);
	}//end testAFailingRegisterIsLoggedAndRefuses()

	public function testEveryOtherAnswerRefusesWithItsReason(): void {
		$cases = [
			[['authorised' => false, 'reason' => 'over-ceiling'], 'The amount is above the financial ceiling of the signatory\'s mandate.', 'over-ceiling'],
			[['authorised' => 'yes', 'reason' => 'no-actor'], 'No signatory is known.', 'no-actor'],
			[['authorised' => false], 'The mandate register cannot be read right now, so this step cannot be approved.', 'register-unreadable'],
			[['authorised' => false, 'reason' => 'no-allocation'], 'The signing official does not hold enough mandate for this decision. Consult the mandate register.', 'no-allocation'],
			['not an array', 'The mandate register cannot be read right now, so this step cannot be approved.', 'register-unreadable'],
		];
		foreach ($cases as [$answer, $sentence, $reason]) {
			$result = $this->guard($answer)->evaluate(['act' => 'a'], [], 'me');
			self::assertSame([false, $sentence, ['authority' => $reason]], $this->row($result));
		}
	}//end testEveryOtherAnswerRefusesWithItsReason()
}//end class
