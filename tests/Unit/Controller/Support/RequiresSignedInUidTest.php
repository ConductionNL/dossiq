<?php

/**
 * The uid of the signed-in caller, or null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\RequiresSignedInUid;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\RequiresSignedInUid
 */
class RequiresSignedInUidTest extends TestCase {

	public function testUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$signedIn = $this->createMock(IUserSession::class);
		$signedIn->method('getUser')->willReturn($user);
		$signedOut = $this->createMock(IUserSession::class);

		$this->assertSame('alice', $this->host(session: $signedIn)->uid());
		$this->assertNull($this->host(session: $signedOut)->uid());
	}

	private function host(IUserSession $session): object {
		return new class ($session) {
			use RequiresSignedInUid;

			public function __construct(private readonly IUserSession $userSession) {
			}

			public function uid(): ?string {
				return $this->requireUser();
			}
		};
	}
}
