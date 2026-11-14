<?php

/**
 * The state of one outbox send.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\OutboxSend;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\OutboxSend
 */
class OutboxSendTest extends TestCase {

	/**
	 * A send starts with the account and outbox it goes through, and nothing saved yet.
	 *
	 * @return void
	 */
	public function testASendStartsWithItsAccountAndNothingSaved(): void {
		$account = new \stdClass();
		$outbox = new \stdClass();

		$send = new OutboxSend(account: $account, outbox: $outbox);

		$this->assertSame($account, $send->account);
		$this->assertSame($outbox, $send->outbox);
		$this->assertSame(0, $send->accountId);
		$this->assertNull($send->saved);
		$this->assertNull($send->outboxId);
	}//end testASendStartsWithItsAccountAndNothingSaved()
}//end class
