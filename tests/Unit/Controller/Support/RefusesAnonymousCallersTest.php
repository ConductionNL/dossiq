<?php

/**
 * An anonymous caller is a 403, a signed-in one passes.
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

use OCA\Dossiq\Controller\Support\RefusesAnonymousCallers;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\RefusesAnonymousCallers
 */
class RefusesAnonymousCallersTest extends TestCase {

	/**
	 * A controller over the given session user.
	 *
	 * @param IUser|null $user The signed-in user.
	 *
	 * @return object
	 */
	private function controller(?IUser $user): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new class ($session) {
			use RefusesAnonymousCallers;

			public function __construct(private readonly IUserSession $userSession) {
			}

			public function check(): mixed {
				return $this->ensureAuthenticated();
			}
		};
	}

	public function testAnonymousCallerIs403(): void {
		$refusal = $this->controller(user: null)->check();

		$this->assertSame(Http::STATUS_FORBIDDEN, $refusal->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $refusal->getData());
	}

	public function testSignedInCallerPasses(): void {
		$this->assertNull($this->controller(user: $this->createMock(IUser::class))->check());
	}
}
