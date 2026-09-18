<?php

/**
 * A case whose leges are outstanding does not move, and says why.
 *
 * 🔴 THE BLOCK IS ENFORCED ON THE WRITE PATH, NOT ONLY ADVERTISED ON THE READ
 * ONE. `availableActions()` publishing a move disabled is a suggestion until
 * `execute()` refuses the same move: the first client that posts it anyway
 * hands a handler a case the gemeente has not been paid for, and nothing
 * records that it happened. Both halves are asserted here, in that order.
 *
 * 🔴 THE LIVE READ IS THE POINT OF THE ORDER. The rule is asked of the case
 * type BEFORE shillinq is asked anything, so a case type that costs nothing
 * pays for no cross-app call; and where the rule does apply, the state is read
 * at the moment of the decision rather than off the hourly projection, because
 * a refusal made on an hour-old word is a citizen who paid at the counter this
 * morning being told they have not. Both are asserted by counting the calls.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\Cases\ExternalHome;
use OCA\Dossiq\Service\Money\CasePaymentReader;
use OCA\Dossiq\Service\Money\CasePaymentState;
use OCA\Dossiq\Service\Money\UnpaidCaseGate;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The payment rule as the lifecycle provider applies it.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */
class UnpaidCaseGuardTest extends TestCase {
	/**
	 * One case of a type that may or may not wait for money.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_PAYLOAD = [
		'id' => 'case-1',
		'caseType' => 'ct-vergunning',
		'status' => 'st-intake',
	];

	/**
	 * An engine offering one move.
	 *
	 * @return StatusTransitionService The double.
	 */
	private function engineOfferingOneMove(): StatusTransitionService {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willReturn([
			'current' => ['statusId' => 'st-intake', 'name' => 'Intake', 'color' => '#fff'],
			'transitions' => [
				['id' => 'tr-1', 'toStatus' => 'st-behandeling', 'name' => 'Take on', 'allowed' => true],
			],
		]);

		return $engine;
	}//end engineOfferingOneMove()

	/**
	 * The provider, over a rule and a state we dictate.
	 *
	 * @param array{paymentRequiredBeforeHandling: bool}|null $rule What the case type declares.
	 * @param string $state What shillinq answers.
	 * @param StatusTransitionService|null $engine The engine double, when the test cares.
	 *
	 * @return array{0: CaseActionProvider, 1: CasePaymentReader} The provider and the reader, so calls can be counted.
	 */
	private function providerFor(?array $rule, string $state, ?StatusTransitionService $engine = null): array {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('paymentRule')->willReturn($rule);

		$payments = $this->createMock(CasePaymentReader::class);
		$payments->method('stateOf')->willReturn(['paymentState' => $state, 'paymentStateCheckedAt' => 'now']);

		$provider = new CaseActionProvider(
			transitionEngine: ($engine ?? $this->engineOfferingOneMove()),
			resultWriter: $this->createMock(CaseResultWriter::class),
			grants: $this->createMock(OpenRegisterGrantsGateway::class),
			externalHome: new ExternalHome(),
			unpaidCases: new UnpaidCaseGate(new CasePaymentState()),
			payments: $payments,
			caseTypes: $caseTypes,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$provider, $payments];
	}//end providerFor()

	/**
	 * An unpaid aanvraag is offered its moves greyed, with the rule on them.
	 *
	 * @return void
	 */
	public function testAnUnpaidCaseIsOfferedItsMovesBlockedAndTheRuleIsNamed(): void {
		[$provider] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => true],
			state: CasePaymentState::OUTSTANDING,
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'alice');

		$this->assertCount(1, $actions);
		$this->assertTrue($actions[0]['blocked']);
		$this->assertStringContainsString('outstanding', (string)$actions[0]['description']);
	}//end testAnUnpaidCaseIsOfferedItsMovesBlockedAndTheRuleIsNamed()

	/**
	 * And posting it anyway meets the same answer.
	 *
	 * @return void
	 */
	public function testPostingTheMoveAnywayIsRefusedWithTheRule(): void {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->expects($this->never())->method('execute');

		[$provider] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => true],
			state: CasePaymentState::OUTSTANDING,
			engine: $engine,
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/outstanding/');

		$provider->execute(object: self::CASE_PAYLOAD, userId: 'alice', action: 'tr-1', data: []);
	}//end testPostingTheMoveAnywayIsRefusedWithTheRule()

	/**
	 * A melding does not wait for money, and does not pay for a cross-app read
	 * to find that out.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoRuleProceedsAndNeverAsksShillinq(): void {
		[$provider, $payments] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => false],
			state: CasePaymentState::OUTSTANDING,
		);
		$payments->expects($this->never())->method('stateOf');

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'alice');

		$this->assertCount(1, $actions);
		// Published, not greyed. Every action carries the key; what the rule
		// changes is its value and the sentence beside it.
		$this->assertFalse($actions[0]['blocked']);
	}//end testACaseTypeWithNoRuleProceedsAndNeverAsksShillinq()

	/**
	 * ADR-102: the money app being unreachable closes the gate and says so.
	 *
	 * @return void
	 */
	public function testAnUnreadableStateRefusesRatherThanAllows(): void {
		[$provider] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => true],
			state: CasePaymentState::STALE,
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'alice');

		$this->assertTrue($actions[0]['blocked']);
		$this->assertStringContainsString('could not be reached', (string)$actions[0]['description']);
	}//end testAnUnreadableStateRefusesRatherThanAllows()

	/**
	 * A paid case moves.
	 *
	 * @return void
	 */
	public function testAPaidCaseProceeds(): void {
		[$provider] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => true],
			state: CasePaymentState::PAID,
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'alice');

		$this->assertFalse($actions[0]['blocked']);
	}//end testAPaidCaseProceeds()

	/**
	 * The case type is read whatever shape the reference arrives in, because a
	 * rule that resolved on flat payloads only would apply to nothing on an
	 * extended read, with nothing to see.
	 *
	 * @return void
	 */
	public function testTheCaseTypeIsFoundOnAnExtendedPayloadToo(): void {
		[$provider] = $this->providerFor(
			rule: ['paymentRequiredBeforeHandling' => true],
			state: CasePaymentState::OUTSTANDING,
		);

		$extended = ['id' => 'case-1', 'caseType' => ['id' => 'ct-vergunning', 'title' => 'Vergunning']];
		$actions = $provider->availableActions(object: $extended, userId: 'alice');

		$this->assertTrue($actions[0]['blocked']);
	}//end testTheCaseTypeIsFoundOnAnExtendedPayloadToo()
}//end class
