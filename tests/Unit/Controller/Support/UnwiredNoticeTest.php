<?php

/**
 * UnwiredNotice — the not-sent outcome when no sender is wired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\UnwiredNotice;
use PHPUnit\Framework\TestCase;

/**
 * The outcome says not sent, names no channel, and gives the reason.
 *
 * @covers \OCA\Dossiq\Controller\Support\UnwiredNotice
 */
final class UnwiredNoticeTest extends TestCase {

	/**
	 * The outcome carries the four notice keys.
	 *
	 * @return void
	 */
	public function testOutcomeIsNotSentAndNotWired(): void {
		self::assertSame(
			expected: [
				'noticeStatus' => 'not-sent',
				'noticeChannel' => '',
				'noticeReasonCode' => 'notice-not-wired',
				'noticeReason' => 'No sender is wired, so the requester was not told.',
			],
			actual: UnwiredNotice::OUTCOME
		);
	}//end testOutcomeIsNotSentAndNotWired()
}//end class
