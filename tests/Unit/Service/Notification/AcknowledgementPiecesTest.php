<?php

/**
 * AcknowledgementOnCase and AcknowledgementRefusals unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Notification\AcknowledgementOnCase;
use OCA\Dossiq\Service\Notification\AcknowledgementRefusals;
use PHPUnit\Framework\TestCase;

/**
 * What a case says about its acknowledgement, and the refusals an acknowledgement answers with.
 *
 * @covers \OCA\Dossiq\Service\Notification\AcknowledgementOnCase
 * @covers \OCA\Dossiq\Service\Notification\AcknowledgementRefusals
 * @uses   \OCA\Dossiq\Exception\NoticeNotSentException
 * @uses   \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AcknowledgementPiecesTest extends TestCase {

	/**
	 * Only a case whose envelope says draft is a draft; a duty that is no block reads empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheCaseSaysWhetherItIsADraftAndWhatItsDutyIs(): void {
		$onCase = new AcknowledgementOnCase();

		$this->assertTrue(condition: $onCase->isDraft(case: ['@self' => ['status' => 'draft']]));
		$this->assertFalse(condition: $onCase->isDraft(case: ['@self' => ['status' => 'published']]));
		$this->assertFalse(condition: $onCase->isDraft(case: ['@self' => 'draft', 'status' => 'draft']));
		$this->assertSame(expected: ['status' => 'met'], actual: $onCase->duty(case: ['acknowledgementDuty' => ['status' => 'met']]));
		$this->assertSame(expected: [], actual: $onCase->duty(case: ['acknowledgementDuty' => 'met']));
		$this->assertSame(expected: [], actual: $onCase->duty(case: []));
	}//end testTheCaseSaysWhetherItIsADraftAndWhatItsDutyIs()

	/**
	 * How receipt was confirmed is trimmed; saying nothing is refused as unprocessable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testHowReceiptWasConfirmedMustBeSaid(): void {
		$refusals = new AcknowledgementRefusals();

		$this->assertSame(expected: 'Per telefoon', actual: $refusals->howStated(how: '  Per telefoon '));

		try {
			$refusals->howStated(how: '   ');
			$this->fail(message: 'An empty answer was accepted.');
		} catch (RefusedException $refused) {
			$this->assertSame(expected: 'acknowledgement-needs-a-reason', actual: $refused->getRule());
			$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $refused->getStatus());
		}
	}//end testHowReceiptWasConfirmedMustBeSaid()

	/**
	 * No address is unprocessable and says so; any other refused transport is indeterminate with its own sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testANoticeNoTransportTookIsRefusedByItsReason(): void {
		$refusals = new AcknowledgementRefusals();

		$noAddress = $refusals->notSent(notice: $this->notice(code: 'no-channel', sentence: 'No channel.'));
		$this->assertSame(expected: 'acknowledgement-no-address', actual: $noAddress->getRule());
		$this->assertSame(expected: RefusedException::STATUS_UNPROCESSABLE, actual: $noAddress->getStatus());

		$failed = $refusals->notSent(notice: $this->notice(code: 'transport-failed', sentence: 'The mail server refused.'));
		$this->assertSame(expected: 'acknowledgement-not-sent', actual: $failed->getRule());
		$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $failed->getStatus());
		$this->assertSame(expected: 'The mail server refused.', actual: $failed->getSentence());
	}//end testANoticeNoTransportTookIsRefusedByItsReason()

	/**
	 * A notice no transport took, with its reason code.
	 *
	 * @param string $code     The reason code.
	 * @param string $sentence The sentence.
	 *
	 * @return NoticeNotSentException The notice.
	 */
	private function notice(string $code, string $sentence): NoticeNotSentException {
		return new NoticeNotSentException(reasonCode: $code, reason: $sentence);
	}//end notice()
}//end class
