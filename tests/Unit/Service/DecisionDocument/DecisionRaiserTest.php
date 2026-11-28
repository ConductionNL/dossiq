<?php

/**
 * Unit tests for DecisionRaiser: a case's decision raised in the decision app, failing closed.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\DecisionDocument\DecisionRaiser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooDecisionSubject;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The decision on a case, raised through DecisionRaiser.
 *
 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DecisionRaiserTest extends TestCase {

	/**
	 * The subject every test raises a decision on.
	 *
	 * @return WooDecisionSubject The subject.
	 */
	private function subject(): WooDecisionSubject {
		return new WooDecisionSubject(
			caseId: 'case-1',
			register: 'dossiq',
			record: ['caseTitle' => 'Brug', 'summary' => ['openbaar' => 2], 'weigeringsgronden' => ['5.1.5'], 'assessmentCount' => 2],
			label: 'Besluit op het Woo-verzoek "Brug"'
		);
	}//end subject()

	/**
	 * A raiser over the given seam.
	 *
	 * @param ContractDecisionDelegationService|null $delegation The seam, or none.
	 * @param LoggerInterface|null                   $logger     The logger.
	 *
	 * @return DecisionRaiser The raiser.
	 */
	private function raiser(?ContractDecisionDelegationService $delegation, ?LoggerInterface $logger = null): DecisionRaiser {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['case_schema' => 'case'][$key] ?? $default)
		);

		return new DecisionRaiser(settingsService: $settings, logger: ($logger ?? new NullLogger()), decisionDelegation: $delegation);
	}//end raiser()

	/**
	 * The decision is raised on the case, named by its label, with the actor and the tally.
	 *
	 * @return void
	 */
	public function testTheDecisionIsRaisedOnTheCase(): void {
		$delegation = $this->createMock(originalClassName: ContractDecisionDelegationService::class);
		$delegation->expects($this->once())->method('raiseDecision')->with(
			ContractDecisionDelegationService::DECISION_TYPE_WOO_DECISION,
			'case-1',
			[
				'subjectRegister' => 'dossiq',
				'subjectSchema' => 'case',
				'subjectId' => 'case-1',
				'subjectLabel' => 'Besluit op het Woo-verzoek "Brug"',
			],
			[
				'actorId' => 'j.dejong',
				'summary' => ['openbaar' => 2],
				'weigeringsgronden' => ['5.1.5'],
				'groundsListVersion' => WooRefusalGrounds::LIST_VERSION,
				'assessmentCount' => 2,
			]
		)->willReturn('decidiq-decision-1');

		$reference = $this->raiser(delegation: $delegation)->raise(subject: $this->subject(), actorId: 'j.dejong');

		$this->assertSame(expected: 'decidiq-decision-1', actual: $reference);
	}//end testTheDecisionIsRaisedOnTheCase()

	/**
	 * Without the seam nothing is raised.
	 *
	 * @return void
	 */
	public function testWithoutTheSeamNothingIsRaised(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'the decision seam is not wired');

		$this->raiser(delegation: null)->raise(subject: $this->subject(), actorId: 'j.dejong');
	}//end testWithoutTheSeamNothingIsRaised()

	/**
	 * A refused raise is logged and fails closed, carrying why.
	 *
	 * @return void
	 */
	public function testARefusedRaiseFailsClosed(): void {
		$cause = new RuntimeException(message: 'decidiq is down');
		$delegation = $this->createMock(originalClassName: ContractDecisionDelegationService::class);
		$delegation->method('raiseDecision')->willThrowException($cause);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains(string: 'case-1'));

		try {
			$this->raiser(delegation: $delegation, logger: $logger)->raise(subject: $this->subject(), actorId: 'j.dejong');
			$this->fail(message: 'A refused raise should fail closed.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Decision service unavailable: decidiq is down', actual: $e->getMessage());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}
	}//end testARefusedRaiseFailsClosed()
}//end class
