<?php

/**
 * Who may change a case's conversation, captures or major-case channel.
 *
 * Golden over the five changing endpoints of CaseConversationController
 * before they share their gate: no session user is 401 with nothing asked;
 * a user without mutation access to the case is 403 with nothing changed;
 * an allowed call reaches its service with the caller where it records one.
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

use OCA\Dossiq\Controller\CaseConversationController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Conversation\CaseCaptureService;
use OCA\Dossiq\Service\Conversation\CaseConversationService;
use OCA\Dossiq\Service\Conversation\MajorCaseDeclaration;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The changing endpoints' refusals and answers.
 *
 * @covers \OCA\Dossiq\Controller\CaseConversationController
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseConversationGateGoldenTest extends TestCase {
	/**
	 * The conversation service double.
	 *
	 * @var CaseConversationService&MockObject
	 */
	private CaseConversationService $conversations;

	/**
	 * The major-case declaration double.
	 *
	 * @var MajorCaseDeclaration&MockObject
	 */
	private MajorCaseDeclaration $major;

	/**
	 * The capture service double.
	 *
	 * @var CaseCaptureService&MockObject
	 */
	private CaseCaptureService $captures;

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
		$this->conversations = $this->createMock(originalClassName: CaseConversationService::class);
		$this->major = $this->createMock(originalClassName: MajorCaseDeclaration::class);
		$this->captures = $this->createMock(originalClassName: CaseCaptureService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Every changing endpoint, with the service and method it reaches.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function endpoints(): array {
		return [
			'start' => ['start', 'conversations', 'startConversation'],
			'end' => ['end', 'conversations', 'recordConversationEnd'],
			'capture' => ['capture', 'captures', 'attachCapture'],
			'declare major' => ['declareMajor', 'major', 'declareMajor'],
			'close major channel' => ['closeMajorChannel', 'major', 'closeMajorChannel'],
		];
	}//end endpoints()

	/**
	 * Without a session user the call is 401 and nothing is asked.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $service  The service it would reach.
	 * @param string $method   The method it would call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutAUserTheCallIsUnauthenticated(string $endpoint, string $service, string $method): void {
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects(self::never())->method('hasCaseMutationAccess');
		$this->$service->expects(self::never())->method($method);

		self::assertSame(expected: [401, ['message' => 'unauthenticated']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutAUserTheCallIsUnauthenticated()

	/**
	 * Without mutation access to the case the call is 403 and nothing changes.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $service  The service it would reach.
	 * @param string $method   The method it would call.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutCaseAccessTheCallIsForbidden(string $endpoint, string $service, string $method): void {
		$user = $this->signIn();
		$this->guard->expects(self::once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->$service->expects(self::never())->method($method);

		self::assertSame(expected: [403, ['ok' => false, 'reason' => 'access_denied']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutCaseAccessTheCallIsForbidden()

	/**
	 * An allowed call reaches its service once and answers its result.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $service  The service it reaches.
	 * @param string $method   The method it calls.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnAllowedCallReachesItsService(string $endpoint, string $service, string $method): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->$service->expects(self::once())->method($method)->willReturn(['ok' => true, 'done' => $method]);

		self::assertSame(expected: [200, ['ok' => true, 'done' => $method]], actual: $this->answer(endpoint: $endpoint));
	}//end testAnAllowedCallReachesItsService()

	/**
	 * Starting, capturing and declaring record the signed-in caller.
	 *
	 * @return void
	 */
	public function testTheCallerIsRecordedWhereTheServiceAsks(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->conversations->expects(self::once())->method('startConversation')->with('c-1', null, 'sanne')->willReturn(['ok' => true]);
		$this->captures->expects(self::once())->method('attachCapture')->with('c-1', '', '', '', null, 'sanne')->willReturn(['ok' => true]);
		$this->major->expects(self::once())->method('declareMajor')->with('c-1', 'sanne')->willReturn(['ok' => true]);

		foreach (['start', 'capture', 'declareMajor'] as $endpoint) {
			$this->answer(endpoint: $endpoint);
		}
	}//end testTheCallerIsRecordedWhereTheServiceAsks()

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
		$controller = new CaseConversationController(
			request: $this->createMock(originalClassName: IRequest::class),
			conversations: $this->conversations,
			major: $this->major,
			captures: $this->captures,
			caseAccessGuard: $this->guard,
			userSession: $this->session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$response = $controller->$endpoint(caseId: 'c-1');
		self::assertInstanceOf(expected: JSONResponse::class, actual: $response);

		return [$response->getStatus(), $response->getData()];
	}//end answer()
}//end class
