<?php

/**
 * Reporting Audience Guard Test
 *
 * Pins the reporting audience guard: a signed-out caller and a caller outside
 * the audience are both refused with 403 and their own message.
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

use OCA\Dossiq\Controller\Support\ReportingAudienceGuard;
use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ReportingAudienceGuard trait.
 *
 * @covers \OCA\Dossiq\Controller\Support\ReportingAudienceGuard
 */
final class ReportingAudienceGuardTest extends TestCase {
	/**
	 * Run the guard on a host using the trait.
	 *
	 * @param IUserSession               $session  The session.
	 * @param ReportingAudienceInterface $audience The audience.
	 *
	 * @return JSONResponse|null
	 */
	private function guard(IUserSession $session, ReportingAudienceInterface $audience): ?JSONResponse {
		$host = new class {
			use ReportingAudienceGuard;

			/**
			 * Expose the guard.
			 *
			 * @param IUserSession               $session  The session.
			 * @param ReportingAudienceInterface $audience The audience.
			 *
			 * @return JSONResponse|null
			 */
			public function run(IUserSession $session, ReportingAudienceInterface $audience): ?JSONResponse {
				return $this->refuseOutsideReportingAudience(userSession: $session, audience: $audience);
			}
		};

		return $host->run(session: $session, audience: $audience);
	}//end guard()

	/**
	 * A session holding the user.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return IUserSession
	 */
	private function session(?IUser $user): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A signed-out caller is refused with 403 without asking the audience.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsRefused(): void {
		$audience = $this->createMock(ReportingAudienceInterface::class);
		$audience->expects($this->never())->method('isInAudience');

		$refusal = $this->guard(session: $this->session(user: null), audience: $audience);

		self::assertInstanceOf(expected: JSONResponse::class, actual: $refusal);
		self::assertSame(expected: 403, actual: $refusal->getStatus());
		self::assertSame(expected: ['message' => 'Not authenticated'], actual: $refusal->getData());
	}//end testASignedOutCallerIsRefused()

	/**
	 * A caller outside the audience gets 403 with the audience's refusal sentence.
	 *
	 * @return void
	 */
	public function testACallerOutsideTheAudienceIsRefused(): void {
		$user = $this->createMock(IUser::class);
		$audience = $this->createMock(ReportingAudienceInterface::class);
		$audience->expects($this->once())->method('isInAudience')->with($user)->willReturn(false);

		$refusal = $this->guard(session: $this->session(user: $user), audience: $audience);

		self::assertInstanceOf(expected: JSONResponse::class, actual: $refusal);
		self::assertSame(expected: 403, actual: $refusal->getStatus());
		self::assertSame(expected: ['message' => ReportingAudienceInterface::REFUSAL], actual: $refusal->getData());
	}//end testACallerOutsideTheAudienceIsRefused()

	/**
	 * A caller in the audience passes.
	 *
	 * @return void
	 */
	public function testACallerInTheAudiencePasses(): void {
		$user = $this->createMock(IUser::class);
		$audience = $this->createMock(ReportingAudienceInterface::class);
		$audience->expects($this->once())->method('isInAudience')->with($user)->willReturn(true);

		self::assertNull(actual: $this->guard(session: $this->session(user: $user), audience: $audience));
	}//end testACallerInTheAudiencePasses()
}//end class
