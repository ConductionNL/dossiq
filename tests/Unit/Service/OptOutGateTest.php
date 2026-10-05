<?php

/**
 * Tests for OptOutGate.
 *
 * The question goes through a dispatcher that really dispatches, carrying the
 * real integriq event class (a verbatim stub when integriq is absent), and is
 * answered by a listener that keeps integriq's decision shape.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 *
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Tests\Support\FakeIntegriqOptOuts;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */
class OptOutGateTest extends TestCase {

	private InMemoryEventDispatcher $dispatcher;

	private string $switch = '';

	/**
	 * Logged warnings: message and context.
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $warnings = [];

	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->switch = '';
		$this->warnings = [];
	}//end setUp()

	private function gate(string $eventRelative = OptOutGate::DECISION_EVENT): OptOutGate {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === OptOutGate::CONFIG_KEY && $this->switch !== '') ? $this->switch : $default
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new OptOutGate($this->dispatcher, $appConfig, $logger, $eventRelative);
	}//end gate()

	public function testTheStubIsTheRealIntegriqShape(): void {
		// The gate resolves the class by name; this run must hold it.
		$this->assertTrue(class_exists(OutboundSendDecisionRequestedEvent::class));
	}//end testTheStubIsTheRealIntegriqShape()

	public function testAnOptedOutRecipientIsRefusedWithTheCode(): void {
		$optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$optOuts->optOut('burger@example.nl', 'case-1');

		$decision = $this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1');

		$this->assertFalse($decision['send']);
		$this->assertSame('opted-out', $decision['code']);
		$this->assertNull($decision['unsubscribe']);
	}//end testAnOptedOutRecipientIsRefusedWithTheCode()

	public function testTheQuestionCarriesDossiqEmailAndTheCase(): void {
		$optOuts = FakeIntegriqOptOuts::on($this->dispatcher);

		$this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1');

		$this->assertCount(1, $optOuts->log);
		$this->assertSame('dossiq', $optOuts->log[0]['sourceApp']);
		$this->assertSame('email', $optOuts->log[0]['channel']);
		$this->assertSame('case-update', $optOuts->log[0]['category']);
		$this->assertSame('case-1', $optOuts->log[0]['caseRef']);
	}//end testTheQuestionCarriesDossiqEmailAndTheCase()

	public function testACaseOptOutDoesNotStopAnotherCaseAndTheLinkComesBack(): void {
		$optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$optOuts->optOut('burger@example.nl', 'case-1');

		$decision = $this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-2');

		$this->assertTrue($decision['send']);
		$this->assertIsArray($decision['unsubscribe']);
		$this->assertStringStartsWith('https://', $decision['unsubscribe']['url']);
	}//end testACaseOptOutDoesNotStopAnotherCaseAndTheLinkComesBack()

	public function testABesluitReachesAnInstanceWideOptOutWithoutALink(): void {
		$optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$optOuts->optOut('burger@example.nl');

		$decision = $this->gate()->ask(recipient: 'burger@example.nl', category: 'besluit', caseRef: 'case-1');

		$this->assertTrue($decision['send']);
		$this->assertNull($decision['unsubscribe']);
		$this->assertTrue($optOuts->log[0]['overridden']);
	}//end testABesluitReachesAnInstanceWideOptOutWithoutALink()

	public function testAnAbsentClassRefusesACaseUpdateAndLogsTheCaseAndCategory(): void {
		$decision = $this->gate(eventRelative: 'Event\\NoSuchDecisionEvent')
			->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1');

		$this->assertFalse($decision['send']);
		$this->assertSame(OptOutGate::CODE_UNAVAILABLE, $decision['code']);
		$this->assertCount(1, $this->warnings);
		$this->assertSame('case-1', $this->warnings[0][1]['caseRef']);
		$this->assertSame('case-update', $this->warnings[0][1]['category']);
	}//end testAnAbsentClassRefusesACaseUpdateAndLogsTheCaseAndCategory()

	public function testAnAbsentClassStillSendsABesluit(): void {
		$decision = $this->gate(eventRelative: 'Event\\NoSuchDecisionEvent')
			->ask(recipient: 'burger@example.nl', category: 'besluit', caseRef: 'case-1');

		$this->assertTrue($decision['send']);
		$this->assertNull($decision['unsubscribe']);
	}//end testAnAbsentClassStillSendsABesluit()

	public function testAnUnhandledEventRefusesACaseUpdateAndPassesABesluit(): void {
		// The class is there, nobody listens: integriq disabled its authority.
		$gate = $this->gate();

		$this->assertSame(
			OptOutGate::CODE_UNAVAILABLE,
			$gate->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1')['code']
		);
		$this->assertTrue($gate->ask(recipient: 'burger@example.nl', category: 'besluit', caseRef: 'case-1')['send']);
	}//end testAnUnhandledEventRefusesACaseUpdateAndPassesABesluit()

	public function testAThrowingListenerRefusesACaseUpdateAndPassesABesluit(): void {
		$this->dispatcher->addListener(
			OutboundSendDecisionRequestedEvent::class,
			static function (): void {
				throw new RuntimeException('database gone');
			}
		);
		$gate = $this->gate();

		$refused = $gate->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1');
		$this->assertFalse($refused['send']);
		$this->assertSame(OptOutGate::CODE_UNAVAILABLE, $refused['code']);
		$this->assertTrue($gate->ask(recipient: 'burger@example.nl', category: 'statutory', caseRef: 'case-1')['send']);
	}//end testAThrowingListenerRefusesACaseUpdateAndPassesABesluit()

	public function testAHandledEventWithoutThisRecipientIsNotASend(): void {
		$this->dispatcher->addListener(
			OutboundSendDecisionRequestedEvent::class,
			static function (OutboundSendDecisionRequestedEvent $event): void {
				$event->setDecision('someone-else@example.nl', ['send' => true]);
				$event->setHandled(true);
			}
		);

		$decision = $this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'case-1');

		$this->assertFalse($decision['send']);
		$this->assertSame(OptOutGate::CODE_UNAVAILABLE, $decision['code']);
	}//end testAHandledEventWithoutThisRecipientIsNotASend()

	public function testOnlyTheWordFalseTurnsTheGateOff(): void {
		$optOuts = FakeIntegriqOptOuts::on($this->dispatcher);
		$optOuts->optOut('burger@example.nl');

		$this->switch = 'no';
		$this->assertFalse($this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'c')['send']);

		$this->switch = 'false';
		$decision = $this->gate()->ask(recipient: 'burger@example.nl', category: 'case-update', caseRef: 'c');
		$this->assertTrue($decision['send']);
		$this->assertSame(OptOutGate::CODE_CHECK_OFF, $decision['code']);
		$this->assertCount(1, $optOuts->log, 'With the gate off, integriq is not asked.');
	}//end testOnlyTheWordFalseTurnsTheGateOff()

	public function testAnUnknownCategoryIsNeverExempt(): void {
		$decision = $this->gate(eventRelative: 'Event\\NoSuchDecisionEvent')
			->ask(recipient: 'burger@example.nl', category: 'Besluit ', caseRef: 'case-1');

		$this->assertFalse($decision['send']);
	}//end testAnUnknownCategoryIsNeverExempt()
}//end class
