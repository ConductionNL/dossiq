<?php

/**
 * Golden record of completing a planned action.
 *
 * Records, per world, the status and body `complete` answers with and every
 * call it makes, so a refactor cannot change who may complete an action or
 * which case's action they complete.
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

use OCA\Dossiq\Controller\PlannedActionController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for PlannedActionController::complete.
 *
 * @covers \OCA\Dossiq\Controller\PlannedActionController
 */
class PlannedActionCompleteGoldenTest extends TestCase {
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
	public function testCompleteAcrossWorlds(): void {
		$planned = [['id' => 'act-1', 'title' => 'Call back'], ['uuid' => 'act-2', 'title' => 'Visit'], ['title' => 'No id']];
		$worlds = [
			'not signed in' => ['act-1', false, true],
			'no write access on the case' => ['act-1', true, false],
			'an action on the case' => ['act-1', true, true],
			'an action named by its uuid' => ['act-2', true, true],
			'an action of another case' => ['act-9', true, true],
			'an empty action id' => ['', true, true],
		];

		$record = [];
		foreach ($worlds as $name => [$actionId, $signedIn, $mayWrite]) {
			$this->calls = [];
			$response = $this->controllerFor(planned: $planned, world: ['user' => $signedIn, 'write' => $mayWrite])->complete('case-1', $actionId);
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'planned-action-complete', value: $record);
	}//end testCompleteAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<int, array<string, mixed>> $planned The actions planned on the case.
	 * @param array<string, bool>              $world   The world.
	 *
	 * @return PlannedActionController
	 */
	private function controllerFor(array $planned, array $world): PlannedActionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseMutationAccess ' . $caseId;
				return $world['write'];
			}
		);

		$service = $this->createMock(PlannedActionService::class);
		$service->method('plannedOn')->willReturnCallback(
			function (string $caseId) use ($planned): array {
				$this->calls[] = 'plannedOn ' . $caseId;
				return $planned;
			}
		);
		$service->method('complete')->willReturnCallback(
			function (array $action, string $completedBy, array $roleHolders = []): ?array {
				$this->calls[] = 'complete ' . json_encode([$action, $completedBy, $roleHolders]);
				return ['id' => 'next-1'];
			}
		);

		return new PlannedActionController('dossiq', $this->createMock(IRequest::class), $service, $session, $guard);
	}//end controllerFor()
}//end class
