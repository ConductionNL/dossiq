<?php

/**
 * An objection can be opened against a named besluit.
 *
 * `BezwaarCreationHook::onBezwaarCreated()` has linked a bezwaar case to the
 * besluit it contests, related the two cases and written the objection record
 * since the bezwaar workflow shipped, and nothing called it.
 * `BezwaarLifecycleListener` observes the same schemas and only logs, so there
 * was no event path either: a bezwaar case could exist and never learn which
 * decision it was against.
 *
 * WHAT IS ASSERTED HERE, AND WHAT IS NOT. The hook's own behaviour, the
 * relating and the objection payload, has its own suite. This one watches the
 * door: that a caller who may not write the case is refused BEFORE the hook
 * runs, and that a request naming no besluit is refused rather than passed on
 * as an empty string for the hook to reject with an operator's error message.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `hasCaseMutationAccess` branch
 * reddens testSomebodyElsesBezwaarIsRefused, and accepting an empty
 * `contestedDecision` reddens testAnObjectionWithNoBesluitIsRefused. Restored
 * after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Controller\BezwaarObjectionController;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The door onto the creation hook.
 *
 * @covers \OCA\Dossiq\Controller\BezwaarObjectionController
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class BezwaarObjectionIsReachableTest extends TestCase {

	/**
	 * A handler opens the objection, and the hook is actually asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function testAHandlerOpensTheObjection(): void {
		$hook = $this->createMock(originalClassName: BezwaarCreationHook::class);
		$hook->expects(self::once())
			->method('onBezwaarCreated')
			->with('case-1', 'besluit-7', ['grounds' => 'Te laat beslist'])
			->willReturn(['id' => 'objection-1', 'contestedDecision' => 'besluit-7']);

		$response = $this->controller(
			hook: $hook,
			mayWrite: true,
			params: ['contestedDecision' => 'besluit-7', 'objection' => ['grounds' => 'Te laat beslist']],
		)->open(caseId: 'case-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('besluit-7', $response->getData()['contestedDecision']);
	}//end testAHandlerOpensTheObjection()

	/**
	 * Somebody who may not write the bezwaar case is refused before the hook runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function testSomebodyElsesBezwaarIsRefused(): void {
		$hook = $this->createMock(originalClassName: BezwaarCreationHook::class);
		$hook->expects(self::never())->method('onBezwaarCreated');

		$response = $this->controller(hook: $hook, mayWrite: false)->open(caseId: 'case-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSomebodyElsesBezwaarIsRefused()

	/**
	 * An objection naming no besluit is refused here, not by the hook.
	 *
	 * The hook's refusal for an empty id reads as an operator's message; a
	 * jurist who forgot to pick the besluit needs to be told to pick one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function testAnObjectionWithNoBesluitIsRefused(): void {
		$hook = $this->createMock(originalClassName: BezwaarCreationHook::class);
		$hook->expects(self::never())->method('onBezwaarCreated');

		$response = $this->controller(
			hook: $hook,
			mayWrite: true,
			params: ['contestedDecision' => '   '],
		)->open(caseId: 'case-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertStringContainsString('besluit', (string)$response->getData()['error']);
	}//end testAnObjectionWithNoBesluitIsRefused()

	/**
	 * An unauthenticated caller gets 401 rather than 403.
	 *
	 * The two are different answers: one says sign in, the other says you are
	 * signed in and this is not yours.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function testAnUnauthenticatedCallerIsToldToSignIn(): void {
		$hook = $this->createMock(originalClassName: BezwaarCreationHook::class);
		$hook->expects(self::never())->method('onBezwaarCreated');

		$response = $this->controller(hook: $hook, mayWrite: true, signedIn: false)->open(caseId: 'case-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnUnauthenticatedCallerIsToldToSignIn()

	/**
	 * The controller under test.
	 *
	 * @param BezwaarCreationHook  $hook     The hook double.
	 * @param bool                 $mayWrite Whether the guard lets the caller write.
	 * @param array<string, mixed> $params   What the request answers.
	 * @param bool                 $signedIn Whether there is a session.
	 *
	 * @return BezwaarObjectionController The controller.
	 */
	private function controller(
		BezwaarCreationHook $hook,
		bool $mayWrite,
		array $params = ['contestedDecision' => 'besluit-7'],
		bool $signedIn = true,
	): BezwaarObjectionController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('jurist');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayWrite);

		return new BezwaarObjectionController(
			appName: 'dossiq',
			request: $request,
			hook: $hook,
			accessGuard: $guard,
			userSession: $session,
		);
	}//end controller()
}//end class
