<?php

/**
 * The actor is the signed-in uid, or `system`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Listener\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\NamesTheActor;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\Support\NamesTheActor
 */
class NamesTheActorTest extends TestCase {

	/**
	 * A listener over the given session user.
	 *
	 * @param IUser|null $user The signed-in user.
	 *
	 * @return object
	 */
	private function listener(?IUser $user): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new class ($session) {
			use NamesTheActor;

			public function __construct(private readonly IUserSession $userSession) {
			}

			public function who(): string {
				return $this->actor();
			}
		};
	}

	public function testSignedInUserIsTheActor(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->assertSame('alice', $this->listener(user: $user)->who());
	}

	public function testNobodySignedInIsSystem(): void {
		$this->assertSame('system', $this->listener(user: null)->who());
	}
}
