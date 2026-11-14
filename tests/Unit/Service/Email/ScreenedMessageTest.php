<?php

/**
 * ScreenedMessage carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\ScreenedMessage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\ScreenedMessage
 */
class ScreenedMessageTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$message = new InboundMessage(accountId: 1, mailbox: 'INBOX', uid: 2);
		$verdict = FilterVerdict::pass(filterName: 'test');
		$results = ['key' => 3];

		$value = new ScreenedMessage(
			message: $message,
			verdict: $verdict,
			results: $results,
		);

		self::assertSame(expected: $message, actual: $value->message);
		self::assertSame(expected: $verdict, actual: $value->verdict);
		self::assertSame(expected: $results, actual: $value->results);
	}//end testEveryPropertyReadsBack()
}//end class
