<?php

/**
 * The case mail's question to integriq: the recipient, the category and the
 * case go to the gate unchanged, and the gate's decision comes back unchanged.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\OptOutGate;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\CaseMailOptOut
 */
class CaseMailOptOutDecisionTest extends TestCase {

	/**
	 * The decision is the gate's, asked about this recipient, category and case on the default channel.
	 *
	 * @return void
	 */
	public function testTheDecisionIsTheGatesAnswerForThisRecipientAndCase(): void {
		$refusal = ['send' => false, 'code' => 'opted_out', 'reason' => 'Ontvanger heeft zich afgemeld', 'unsubscribe' => null];
		$gate = $this->createMock(OptOutGate::class);
		$gate->expects(self::once())
			->method('ask')
			->with('burger@example.nl', 'case_update', 'case-uuid-1')
			->willReturn($refusal);

		$optOut = new CaseMailOptOut(gate: $gate, l10n: $this->createMock(IL10N::class));

		self::assertSame($refusal, $optOut->decide(recipient: 'burger@example.nl', category: 'case_update', caseId: 'case-uuid-1'));
	}//end testTheDecisionIsTheGatesAnswerForThisRecipientAndCase()

	/**
	 * An allowing decision is passed through as well, unsubscribe data included.
	 *
	 * @return void
	 */
	public function testAnAllowingDecisionIsPassedThroughWhole(): void {
		$allowed = ['send' => true, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => ['url' => 'https://example.nl/afmelden/abc']];
		$gate = $this->createMock(OptOutGate::class);
		$gate->method('ask')->willReturn($allowed);

		$optOut = new CaseMailOptOut(gate: $gate, l10n: $this->createMock(IL10N::class));

		self::assertSame($allowed, $optOut->decide(recipient: 'a@b.nl', category: 'besluit', caseId: 'c'));
	}//end testAnAllowingDecisionIsPassedThroughWhole()
}//end class
