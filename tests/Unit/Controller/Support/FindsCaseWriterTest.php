<?php

/**
 * The writer is the signed-in caller with change access, else null.
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

use OCA\Dossiq\Controller\Support\FindsCaseWriter;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\FindsCaseWriter
 */
class FindsCaseWriterTest extends TestCase {

	/**
	 * A controller over the given caller and access.
	 *
	 * @param IUser|null $user   The signed-in user.
	 * @param bool       $access Whether the guard grants change access.
	 *
	 * @return object
	 */
	private function controller(?IUser $user, bool $access): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(CaseAccessGuardInterface::class);
		$guard->method('hasCaseMutationAccess')->willReturn($access);

		return new class ($session, $guard) {
			use FindsCaseWriter;

			public function __construct(
				private readonly IUserSession $userSession,
				private readonly CaseAccessGuardInterface $accessGuard,
			) {
			}

			public function writer(string $caseId): ?IUser {
				return $this->writerOf(caseId: $caseId);
			}
		};
	}

	public function testWriter(): void {
		$user = $this->createMock(IUser::class);

		$this->assertSame($user, $this->controller(user: $user, access: true)->writer('c1'));
		$this->assertNull($this->controller(user: $user, access: false)->writer('c1'));
		$this->assertNull($this->controller(user: null, access: true)->writer('c1'));
	}
}
