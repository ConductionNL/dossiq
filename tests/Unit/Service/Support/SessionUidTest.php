<?php

/**
 * SessionUid — the signed-in caller's user id, or ''.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\SessionUid;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The uid with and without a session.
 *
 * @covers \OCA\Dossiq\Service\Support\SessionUid
 */
final class SessionUidTest extends TestCase {

	/**
	 * A signed-in caller answers their uid, no caller the empty string.
	 *
	 * @return void
	 */
	public function testUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($user, null);
		$uid = new SessionUid(userSession: $session);

		self::assertSame('alice', $uid->uid());
		self::assertSame('', $uid->uid());
	}//end testUid()
}//end class
