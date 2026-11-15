<?php

/**
 * Every answer of the two case acts endpoints that read, allowed and refused.
 *
 * `acts()` and `archiveReadiness()` refuse a caller who is not signed in
 * (401) and a caller who may not read the case (403), before anything is
 * read. A reader is served; `acts()` marks every act as not allowed for a
 * reader who may not change the case. These pin those answers on both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseActsController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Lifecycle\CaseActs;
use OCA\Dossiq\Service\Lifecycle\CaseActsOverview;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CaseActsReaderGoldenTest extends TestCase {

	/**
	 * What was read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * The controller for a caller with the given reach.
	 *
	 * @param bool $signedIn  Whether anyone is signed in.
	 * @param bool $mayRead   Whether the caller may read the case.
	 * @param bool $mayChange Whether the caller may change the case.
	 *
	 * @return CaseActsController The controller.
	 */
	private function controller(bool $signedIn, bool $mayRead = true, bool $mayChange = true): CaseActsController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('sanne');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			function (string $caseId) use ($mayRead): bool {
				$this->reads[] = 'may read ' . $caseId;
				return $mayRead;
			}
		);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($mayChange): bool {
				$this->reads[] = 'may change ' . $caseId;
				return $mayChange;
			}
		);

		$overview = $this->createMock(originalClassName: CaseActsOverview::class);
		$overview->method('overview')->willReturnCallback(
			function (string $caseId): array {
				$this->reads[] = 'overview ' . $caseId;
				return ['acts' => [['name' => 'finish', 'allowed' => true]], 'alwaysAvailable' => []];
			}
		);
		$overview->method('archiveReadiness')->willReturnCallback(
			function (string $caseId): array {
				$this->reads[] = 'readiness ' . $caseId;
				return ['ready' => false];
			}
		);

		return new CaseActsController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			acts: $this->createMock(originalClassName: CaseActs::class),
			overview: $overview,
			caseAccessGuard: $guard,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * An endpoint's answer as [status, body].
	 *
	 * @param CaseActsController $controller The controller.
	 * @param string             $endpoint   `acts` or `archiveReadiness`.
	 *
	 * @return array{0: int, 1: mixed} The answer.
	 */
	private function call(CaseActsController $controller, string $endpoint): array {
		$response = $controller->{$endpoint}('c-1');
		self::assertInstanceOf(JSONResponse::class, $response);
		return [$response->getStatus(), $response->getData()];
	}//end call()

	/**
	 * Both endpoints.
	 *
	 * @return array<string, array{0: string}> Per endpoint: its method.
	 */
	public static function endpoints(): array {
		return ['acts' => ['acts'], 'archive readiness' => ['archiveReadiness']];
	}//end endpoints()

	/**
	 * Nobody signed in is 401, and nothing is read.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testNobodySignedInIsRefused(string $endpoint): void {
		self::assertSame(
			[401, ['message' => 'Sign in first.', 'error' => 'not-authenticated']],
			$this->call(controller: $this->controller(signedIn: false), endpoint: $endpoint)
		);
		self::assertSame([], $this->reads);
	}//end testNobodySignedInIsRefused()

	/**
	 * A caller who may not read the case is 403, and the case is not read.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testACallerWhoMayNotReadIsRefused(string $endpoint): void {
		self::assertSame(
			[403, ['message' => 'You do not work on this case.', 'error' => 'not-authorized']],
			$this->call(controller: $this->controller(signedIn: true, mayRead: false), endpoint: $endpoint)
		);
		self::assertSame(['may read c-1'], $this->reads);
	}//end testACallerWhoMayNotReadIsRefused()

	/**
	 * A reader is served; a reader who may not change the case sees every act refused.
	 *
	 * @return void
	 */
	public function testAReaderIsServed(): void {
		self::assertSame([200, ['ready' => false]], $this->call(controller: $this->controller(signedIn: true), endpoint: 'archiveReadiness'));
		self::assertSame(
			[200, ['acts' => [['name' => 'finish', 'allowed' => true]], 'alwaysAvailable' => []]],
			$this->call(controller: $this->controller(signedIn: true), endpoint: 'acts')
		);
		self::assertSame(
			[200, ['acts' => [['name' => 'finish', 'allowed' => false, 'reason' => 'Claim this case first: only its handler acts on it.']], 'alwaysAvailable' => []]],
			$this->call(controller: $this->controller(signedIn: true, mayChange: false), endpoint: 'acts')
		);
		self::assertSame(
			['may read c-1', 'readiness c-1', 'may read c-1', 'may change c-1', 'overview c-1', 'may read c-1', 'may change c-1', 'overview c-1'],
			$this->reads
		);
	}//end testAReaderIsServed()
}//end class
