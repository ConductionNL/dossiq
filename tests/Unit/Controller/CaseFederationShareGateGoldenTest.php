<?php

/**
 * Every answer of the federated share endpoints a local caller reaches, allowed and refused.
 *
 * Revoking a federated share, posting activity on it and listing its activity
 * all refuse a caller who is not signed in (401), and refuse a caller who may
 * not reach the share's case (403). A share without a resolvable case is not
 * checked against a case. These pin those answers, and what each endpoint
 * does once the caller is let through, on all three endpoints.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseFederationController;
use OCA\Dossiq\Service\CaseCollaborationService;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\CaseTransferService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CaseFederationShareGateGoldenTest extends TestCase {

	/**
	 * What the services were asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The controller for a caller, a share's case and that caller's reach.
	 *
	 * @param string|null $uid     Who is signed in, null for nobody.
	 * @param string|null $caseId  The case the share resolves to, null for none.
	 * @param bool        $reaches Whether the caller may reach that case.
	 * @param string      $message The posted message.
	 * @param bool        $fails   Whether the service answers an error.
	 *
	 * @return CaseFederationController The controller.
	 */
	private function controller(?string $uid, ?string $caseId, bool $reaches = true, string $message = 'Hallo', bool $fails = false): CaseFederationController {
		$sharing = $this->createMock(originalClassName: CaseSharingService::class);
		$sharing->method('getCaseIdForFederatedShare')->willReturnCallback(
			function (string $shareId) use ($caseId): ?string {
				$this->calls[] = 'case of ' . $shareId;
				return $caseId;
			}
		);
		$sharing->method('canUserAccessCase')->willReturnCallback(
			function (string $case, string $user) use ($reaches): bool {
				$this->calls[] = 'reach ' . $case . ' as ' . $user;
				return $reaches;
			}
		);
		$sharing->method('revokeFederatedShare')->willReturnCallback(
			function (string $shareId, string $user) use ($fails): array {
				$this->calls[] = 'revoke ' . $shareId . ' as ' . $user;
				return ($fails === true ? ['error' => 'remote down'] : ['id' => $shareId, 'revoked' => true]);
			}
		);

		$collab = $this->createMock(originalClassName: CaseCollaborationService::class);
		$collab->method('postLocalActivity')->willReturnCallback(
			function (string $shareId, string $user, string $text) use ($fails): array {
				$this->calls[] = 'post ' . $text . ' on ' . $shareId . ' as ' . $user;
				return ($fails === true ? ['error' => 'remote down'] : ['message' => $text]);
			}
		);
		$collab->method('listActivity')->willReturnCallback(
			function (string $shareId): array {
				$this->calls[] = 'list ' . $shareId;
				return [['message' => 'eerder']];
			}
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'message' ? $message : $default));

		return new CaseFederationController(
			request: $request,
			caseSharingService: $sharing,
			caseTransferService: $this->createMock(originalClassName: CaseTransferService::class),
			collabService: $collab,
			userSession: $session,
			throttler: $this->createMock(originalClassName: IThrottler::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * An endpoint's answer as [status, body].
	 *
	 * @param CaseFederationController $controller The controller.
	 * @param string                   $endpoint   `revoke`, `post` or `list`.
	 *
	 * @return array{0: int, 1: mixed} The answer.
	 */
	private function call(CaseFederationController $controller, string $endpoint): array {
		$response = match ($endpoint) {
			'revoke' => $controller->revokeFederatedShare(shareId: 'fs-1'),
			'post'   => $controller->postActivity(federatedShareId: 'fs-1'),
			'list'   => $controller->listActivity(federatedShareId: 'fs-1'),
		};
		self::assertInstanceOf(JSONResponse::class, $response);

		return [$response->getStatus(), $response->getData()];
	}//end call()

	/**
	 * The three endpoints.
	 *
	 * @return array<string, array{0: string}> Per endpoint: its name.
	 */
	public static function endpoints(): array {
		return ['revoke' => ['revoke'], 'post activity' => ['post'], 'list activity' => ['list']];
	}//end endpoints()

	/**
	 * Nobody signed in is 401, and nothing is asked.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testNobodySignedInIsRefused(string $endpoint): void {
		self::assertSame(
			[401, ['success' => false, 'error' => 'Not authenticated']],
			$this->call(controller: $this->controller(uid: null, caseId: 'c-1'), endpoint: $endpoint)
		);
		self::assertSame([], $this->calls);
	}//end testNobodySignedInIsRefused()

	/**
	 * A caller who may not reach the share's case is 403, and nothing is done.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testACallerWhoMayNotReachTheCaseIsRefused(string $endpoint): void {
		self::assertSame(
			[403, ['success' => false, 'error' => 'Access denied: you are not assigned to this case']],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1', reaches: false), endpoint: $endpoint)
		);
		self::assertSame(['case of fs-1', 'reach c-1 as sanne'], $this->calls);
	}//end testACallerWhoMayNotReachTheCaseIsRefused()

	/**
	 * A caller who may reach the case is let through, and so is any caller on a share without a case.
	 *
	 * @return void
	 */
	public function testAllowedCallersAreServed(): void {
		self::assertSame(
			[200, ['success' => true, 'share' => ['id' => 'fs-1', 'revoked' => true]]],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1'), endpoint: 'revoke')
		);
		self::assertSame(
			[200, ['success' => true, 'activity' => ['message' => 'Hallo']]],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: null), endpoint: 'post')
		);
		self::assertSame(
			[200, ['success' => true, 'entries' => [['message' => 'eerder']]]],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1'), endpoint: 'list')
		);
		self::assertSame(
			[
				'case of fs-1', 'reach c-1 as sanne', 'revoke fs-1 as sanne',
				'case of fs-1', 'post Hallo on fs-1 as sanne',
				'case of fs-1', 'reach c-1 as sanne', 'list fs-1',
			],
			$this->calls
		);
	}//end testAllowedCallersAreServed()

	/**
	 * Past the gate: an empty message is 400, and a service error is 502.
	 *
	 * @return void
	 */
	public function testPastTheGate(): void {
		self::assertSame(
			[400, ['success' => false, 'error' => 'message is required']],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1', message: ''), endpoint: 'post')
		);
		self::assertSame(
			[502, ['success' => false, 'error' => 'remote down']],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1', fails: true), endpoint: 'post')
		);
		self::assertSame(
			[502, ['success' => false, 'error' => 'remote down']],
			$this->call(controller: $this->controller(uid: 'sanne', caseId: 'c-1', fails: true), endpoint: 'revoke')
		);
	}//end testPastTheGate()
}//end class
