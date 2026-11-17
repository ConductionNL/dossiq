<?php

/**
 * NamesTheSessionUser — the signed-in session user's uid.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\NamesTheSessionUser;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A signed-in user answers its uid; without one 'system' or ''.
 *
 * @covers \OCA\Dossiq\Service\Support\NamesTheSessionUser
 */
final class NamesTheSessionUserTest extends TestCase {

	/**
	 * A class exposing the trait's steps over a session holding the user.
	 *
	 * @param IUser|null $user The signed-in user, or null.
	 *
	 * @return object
	 */
	private function subject(?IUser $user): object {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new class($session) {
			use NamesTheSessionUser {
				sessionActor as public;
				sessionUid as public;
			}

			/**
			 * Hold the session.
			 *
			 * @param IUserSession $userSession The session.
			 */
			public function __construct(private readonly IUserSession $userSession) {
			}//end __construct()
		};
	}//end subject()

	/**
	 * A signed-in user answers its uid for both steps.
	 *
	 * @return void
	 */
	public function testSignedInUserAnswersItsUid(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$subject = $this->subject(user: $user);

		self::assertSame(expected: 'alice', actual: $subject->sessionActor());
		self::assertSame(expected: 'alice', actual: $subject->sessionUid());
	}//end testSignedInUserAnswersItsUid()

	/**
	 * Without a session user the actor is 'system' and the uid empty.
	 *
	 * @return void
	 */
	public function testNoUserAnswersSystemOrEmpty(): void {
		$subject = $this->subject(user: null);

		self::assertSame(expected: 'system', actual: $subject->sessionActor());
		self::assertSame(expected: '', actual: $subject->sessionUid());
	}//end testNoUserAnswersSystemOrEmpty()
}//end class
