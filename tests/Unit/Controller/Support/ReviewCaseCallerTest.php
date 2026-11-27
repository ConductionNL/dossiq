<?php

/**
 * ReviewCaseCaller Unit Tests
 *
 * The caller of a review route: a 401 `not-authenticated` without a session
 * user (the guard not asked), the translated 403 without read or change
 * access, and the user when the guard allows the read or the change.
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
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ReviewCaseCaller;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReviewCaseCaller.
 *
 * @covers \OCA\Dossiq\Controller\Support\ReviewCaseCaller
 * @covers \OCA\Dossiq\Controller\Support\AnswersNoCaseAccess
 */
final class ReviewCaseCallerTest extends TestCase {

	/**
	 * The caller over a session with or without a user, and a guard answering read and change.
	 *
	 * @param IUser|null $user    The session user.
	 * @param bool       $reads   Whether the guard allows the read.
	 * @param bool       $changes Whether the guard allows the change.
	 *
	 * @return ReviewCaseCaller
	 */
	private function caller(?IUser $user, bool $reads, bool $changes): ReviewCaseCaller {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(originalClassName: CaseAccessGuardInterface::class);
		if ($user === null) {
			$guard->expects($this->never())->method('hasCaseReadAccess');
			$guard->expects($this->never())->method('hasCaseMutationAccess');
		}

		$guard->method('hasCaseReadAccess')->with('case-1', $user)->willReturn($reads);
		$guard->method('hasCaseMutationAccess')->with('case-1', $user)->willReturn($changes);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL: ' . $text);
		return new ReviewCaseCaller(userSession: $session, accessGuard: $guard, l10n: $l10n);
	}//end caller()

	/**
	 * Without a session user both answer 401, and the guard is not asked.
	 *
	 * @return void
	 */
	public function testWithoutASessionUserItIs401(): void {
		$caller = $this->caller(user: null, reads: true, changes: true);

		foreach ([$caller->requireReader(id: 'case-1'), $caller->requireWriter(id: 'case-1')] as $answer) {
			$this->assertInstanceOf(expected: JSONResponse::class, actual: $answer);
			$this->assertSame(expected: [401, ['error' => 'not-authenticated']], actual: [$answer->getStatus(), $answer->getData()]);
		}
	}//end testWithoutASessionUserItIs401()

	/**
	 * A reader who may not change gets the user for a read and the translated 403 for a change.
	 *
	 * @return void
	 */
	public function testAReaderMayReadButNotChange(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$caller = $this->caller(user: $user, reads: true, changes: false);

		$this->assertSame(expected: $user, actual: $caller->requireReader(id: 'case-1'));
		$refused = $caller->requireWriter(id: 'case-1');
		$this->assertInstanceOf(expected: JSONResponse::class, actual: $refused);
		$this->assertSame(
			expected: [403, ['error' => 'no-case-access', 'message' => 'NL: You do not have access to this case']],
			actual: [$refused->getStatus(), $refused->getData()]
		);
	}//end testAReaderMayReadButNotChange()

	/**
	 * Without read access the read is refused; with change access the change is allowed.
	 *
	 * @return void
	 */
	public function testEachQuestionIsAskedOnItsOwn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$caller = $this->caller(user: $user, reads: false, changes: true);

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $caller->requireReader(id: 'case-1'));
		$this->assertSame(expected: $user, actual: $caller->requireWriter(id: 'case-1'));
	}//end testEachQuestionIsAskedOnItsOwn()
}//end class
