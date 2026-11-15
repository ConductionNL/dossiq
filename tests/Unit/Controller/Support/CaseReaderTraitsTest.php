<?php

/**
 * The case-reader refusals: no session, no read access, or the caller.
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

use OCA\Dossiq\Controller\Support\FindsCaseReaders;
use OCA\Dossiq\Controller\Support\RefusesUnreadableCases;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\FindsCaseReaders
 * @covers \OCA\Dossiq\Controller\Support\RefusesUnreadableCases
 */
class CaseReaderTraitsTest extends TestCase {

	/**
	 * A host over a session with the given user and a guard with the given answer.
	 *
	 * @param IUser|null $user     The signed-in user.
	 * @param bool       $mayRead  The guard's answer.
	 *
	 * @return object
	 */
	private function host(?IUser $user, bool $mayRead): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(CaseAccessGuardInterface::class);
		$guard->method('hasCaseReadAccess')->willReturn($mayRead);

		return new class ($session, $guard) {
			use FindsCaseReaders;
			use RefusesUnreadableCases;

			public function __construct(private readonly IUserSession $userSession, private readonly CaseAccessGuardInterface $caseAccess) {
			}

			public function reader(string $caseId): mixed {
				return $this->caseReader(caseId: $caseId);
			}

			public function refusal(string $caseId): mixed {
				return $this->unreadableCaseRefusal(caseId: $caseId);
			}
		};
	}

	public function testReaderIsTheCaller(): void {
		$user = $this->createMock(IUser::class);
		$this->assertSame($user, $this->host($user, true)->reader('c-1'));
		$this->assertNull($this->host($user, true)->refusal('c-1'));
	}

	public function testNoSessionIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->host(null, true)->reader('c-1')->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $this->host(null, true)->reader('c-1')->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->host(null, true)->refusal('c-1')->getStatus());
	}

	public function testNoReadAccessIsRefused(): void {
		$user = $this->createMock(IUser::class);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->host($user, false)->reader('c-1')->getStatus());
		$this->assertSame(['message' => 'You may not read this case.'], $this->host($user, false)->refusal('c-1')->getData());
	}
}
