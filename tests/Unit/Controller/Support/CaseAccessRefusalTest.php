<?php

/**
 * CaseAccessRefusal — the 401 or 403 a case endpoint answers before it acts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\CaseAccessRefusal;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who is refused, and with which status.
 *
 * @covers \OCA\Dossiq\Controller\Support\CaseAccessRefusal
 */
final class CaseAccessRefusalTest extends TestCase {

	/**
	 * A refusal over the given caller and access.
	 *
	 * @param bool $signedIn Whether a caller is signed in.
	 * @param bool $access   Whether the guard grants access.
	 *
	 * @return CaseAccessRefusal
	 */
	private function refusal(bool $signedIn, bool $access): CaseAccessRefusal {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$guard = $this->createMock(CaseAccessGuardInterface::class);
		$guard->method('hasCaseReadAccess')->willReturn($access);
		$guard->method('hasCaseMutationAccess')->willReturn($access);

		return new CaseAccessRefusal(userSession: $session, guard: $guard);
	}//end refusal()

	/**
	 * No session is a 401, no access a 403, access nothing.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->refusal(signedIn: false, access: true)->requireReadAccess(caseId: 'c1')?->getStatus());
		self::assertSame(['error' => 'Not authenticated'], $this->refusal(signedIn: false, access: true)->requireChangeAccess(caseId: 'c1')?->getData());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->refusal(signedIn: true, access: false)->requireReadAccess(caseId: 'c1')?->getStatus());
		self::assertSame(['error' => 'Not authorized'], $this->refusal(signedIn: true, access: false)->requireChangeAccess(caseId: 'c1')?->getData());
		self::assertNull($this->refusal(signedIn: true, access: true)->requireReadAccess(caseId: 'c1'));
		self::assertNull($this->refusal(signedIn: true, access: true)->requireChangeAccess(caseId: 'c1'));
	}//end testRefusals()

	/**
	 * The caller is handed back when access holds, else the same refusals.
	 *
	 * @return void
	 */
	public function testCallerLookups(): void {
		self::assertInstanceOf(IUser::class, $this->refusal(signedIn: true, access: true)->reader(caseId: 'c1'));
		self::assertInstanceOf(IUser::class, $this->refusal(signedIn: true, access: true)->writer(caseId: 'c1'));

		$noSession = $this->refusal(signedIn: false, access: true)->reader(caseId: 'c1');
		self::assertInstanceOf(JSONResponse::class, $noSession);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $noSession->getStatus());

		$noAccess = $this->refusal(signedIn: true, access: false)->writer(caseId: 'c1');
		self::assertInstanceOf(JSONResponse::class, $noAccess);
		self::assertSame(['error' => 'Not authorized'], $noAccess->getData());
	}//end testCallerLookups()
}//end class
