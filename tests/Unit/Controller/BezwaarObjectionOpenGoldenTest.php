<?php

/**
 * Golden record of opening an objection on a case.
 *
 * Records, per world, the status and body `open` answers with and every call
 * it makes, so a refactor cannot change who may open an objection or what
 * reaches the creation hook.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BezwaarObjectionController;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for BezwaarObjectionController::open.
 *
 * @covers \OCA\Dossiq\Controller\BezwaarObjectionController
 */
class BezwaarObjectionOpenGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the endpoint distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testOpenAcrossWorlds(): void {
		$params = ['contestedDecision' => ' besluit-1 ', 'objection' => ['grounds' => 'too late']];
		$allowed = ['user' => true, 'access' => true, 'hook' => 'ok'];
		$worlds = [
			'not signed in' => [$params, ['user' => false] + $allowed],
			'no write access on the case' => [$params, ['access' => false] + $allowed],
			'no contested decision' => [['objection' => ['grounds' => 'x']], $allowed],
			'a blank contested decision' => [['contestedDecision' => '   '], $allowed],
			'opened' => [$params, $allowed],
			'opened with an objection that is not a list' => [['contestedDecision' => 'besluit-1', 'objection' => 'text'], $allowed],
			'the hook refuses' => [$params, ['hook' => 'refuse'] + $allowed],
		];

		$record = [];
		foreach ($worlds as $name => [$request, $world]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $request, world: $world)->open('case-1');
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'bezwaar-objection-open', value: $record);
	}//end testOpenAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return BezwaarObjectionController
	 */
	private function controllerFor(array $params, array $world): BezwaarObjectionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $this->createMock(IUser::class) : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseMutationAccess ' . $caseId;
				return $world['access'];
			}
		);

		$hook = $this->createMock(BezwaarCreationHook::class);
		$hook->method('onBezwaarCreated')->willReturnCallback(
			function (string $objectionCaseId, string $contestedDecisionId, array $objectionPayload = []) use ($world): array {
				$this->calls[] = 'onBezwaarCreated ' . json_encode([$objectionCaseId, $contestedDecisionId, $objectionPayload]);
				if ($world['hook'] === 'refuse') {
					throw new RuntimeException('Contested decision not found');
				}

				return ['id' => 'bezwaar-1'];
			}
		);

		return new BezwaarObjectionController(
			appName: 'dossiq',
			request: $request,
			hook: $hook,
			accessGuard: $guard,
			userSession: $session,
		);
	}//end controllerFor()
}//end class
