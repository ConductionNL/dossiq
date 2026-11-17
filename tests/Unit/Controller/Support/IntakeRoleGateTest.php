<?php

/**
 * Intake Role Gate Test
 *
 * Pins who may run intake: a signed-out caller gets 401, a caller the intake
 * policy refuses gets 403, everyone else passes; and the caller id it reports.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\IntakeRoleGate;
use OCA\Dossiq\Kernel\Contracts\IntakePolicyInterface;
use OCA\Dossiq\Service\Support\SessionUid;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntakeRoleGate.
 */
#[CoversClass(IntakeRoleGate::class)]
#[UsesClass(SessionUid::class)]
final class IntakeRoleGateTest extends TestCase {
	/**
	 * A session holding a user with the uid, or none.
	 *
	 * @param string|null $uid The uid, or null for no user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A signed-out caller is refused with 401 and the policy is not asked.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsUnauthenticated(): void {
		$policy = $this->createMock(IntakePolicyInterface::class);
		$policy->expects($this->never())->method('mayRunIntake');

		$refusal = (new IntakeRoleGate(userSession: $this->session(uid: null), policy: $policy))->refusal();

		self::assertInstanceOf(expected: JSONResponse::class, actual: $refusal);
		self::assertSame(expected: 401, actual: $refusal->getStatus());
		self::assertSame(expected: ['message' => 'unauthenticated'], actual: $refusal->getData());
	}//end testASignedOutCallerIsUnauthenticated()

	/**
	 * A caller the policy refuses gets 403; the policy is asked with the caller's uid.
	 *
	 * @return void
	 */
	public function testACallerThePolicyRefusesIsForbidden(): void {
		$policy = $this->createMock(IntakePolicyInterface::class);
		$policy->expects($this->once())->method('mayRunIntake')->with('alice')->willReturn(false);

		$refusal = (new IntakeRoleGate(userSession: $this->session(uid: 'alice'), policy: $policy))->refusal();

		self::assertInstanceOf(expected: JSONResponse::class, actual: $refusal);
		self::assertSame(expected: 403, actual: $refusal->getStatus());
		self::assertSame(expected: ['message' => 'Not authorized'], actual: $refusal->getData());
	}//end testACallerThePolicyRefusesIsForbidden()

	/**
	 * A caller the policy allows passes.
	 *
	 * @return void
	 */
	public function testAnAllowedCallerPasses(): void {
		$policy = $this->createMock(IntakePolicyInterface::class);
		$policy->expects($this->once())->method('mayRunIntake')->with('bob')->willReturn(true);

		self::assertNull(actual: (new IntakeRoleGate(userSession: $this->session(uid: 'bob'), policy: $policy))->refusal());
	}//end testAnAllowedCallerPasses()

	/**
	 * The caller id is the session uid, or empty when signed out.
	 *
	 * @return void
	 */
	public function testCallerIdIsTheSessionUid(): void {
		$policy = $this->createMock(IntakePolicyInterface::class);

		self::assertSame(expected: 'carol', actual: (new IntakeRoleGate(userSession: $this->session(uid: 'carol'), policy: $policy))->callerId());
		self::assertSame(expected: '', actual: (new IntakeRoleGate(userSession: $this->session(uid: null), policy: $policy))->callerId());
	}//end testCallerIdIsTheSessionUid()
}//end class
