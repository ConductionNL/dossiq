<?php

/**
 * Who may raise or clear a case's attention flag.
 *
 * Golden over CaseAttentionController::raise() and clear() before they share
 * their gate: no session user is 401 with the not-signed-in sentence and
 * nothing asked; a user without mutation access is 403 with the not-yours
 * sentence and nothing changed; an allowed call changes the flag as the
 * signed-in user with the given reason.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseAttentionController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseAttentionFlagService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The flag-changing endpoints' refusals and answers.
 *
 * @covers \OCA\Dossiq\Controller\CaseAttentionController
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseAttentionGateGoldenTest extends TestCase {
	/**
	 * The flag service double.
	 *
	 * @var CaseAttentionFlagService&MockObject
	 */
	private CaseAttentionFlagService $flags;

	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flags = $this->createMock(originalClassName: CaseAttentionFlagService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Both flag-changing endpoints.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return ['raise' => ['raise'], 'clear' => ['clear']];
	}//end endpoints()

	/**
	 * Without a session user the call is 401 and nothing is asked.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutAUserTheCallIsUnauthenticated(string $endpoint): void {
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects(self::never())->method('hasCaseMutationAccess');
		$this->flags->expects(self::never())->method($endpoint);

		self::assertSame(expected: [401, ['error' => 'You are not signed in.']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutAUserTheCallIsUnauthenticated()

	/**
	 * Without mutation access the call is 403 and the flag is not changed.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutMutationAccessTheCallIsForbidden(string $endpoint): void {
		$user = $this->signIn();
		$this->guard->expects(self::once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->flags->expects(self::never())->method($endpoint);

		self::assertSame(
			expected: [403, ['error' => 'You cannot change the attention flag on this case.']],
			actual: $this->answer(endpoint: $endpoint)
		);
	}//end testWithoutMutationAccessTheCallIsForbidden()

	/**
	 * An allowed call changes the flag as the signed-in user with the reason.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAllowedCallChangesTheFlag(string $endpoint): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->flags->expects(self::once())->method($endpoint)->with('c-1', 'sanne', 'why')->willReturn(['raised' => true]);

		self::assertSame(expected: [200, ['raised' => true]], actual: $this->answer(endpoint: $endpoint));
	}//end testAnAllowedCallChangesTheFlag()

	/**
	 * Sign a user in.
	 *
	 * @return IUser The user.
	 */
	private function signIn(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('sanne');
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * The status and data of one endpoint's answer for case c-1.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return array{0: int, 1: mixed} Status and data.
	 */
	private function answer(string $endpoint): array {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new CaseAttentionController(
			request: $this->createMock(originalClassName: IRequest::class),
			flags: $this->flags,
			caseAccessGuard: $this->guard,
			userSession: $this->session,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$response = $controller->$endpoint(caseId: 'c-1', reason: 'why');

		return [$response->getStatus(), $response->getData()];
	}//end answer()
}//end class
