<?php

/**
 * Golden record of the task cancel endpoint.
 *
 * Records, per world, the status and body the endpoint answers with and every
 * call it makes, so a refactor cannot change who may cancel a task or what a
 * refusal says.
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

use LogicException;
use OCA\Dossiq\Controller\CaseTaskCancelController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Golden test for CaseTaskCancelController::cancel.
 *
 * @covers \OCA\Dossiq\Controller\CaseTaskCancelController
 * @uses \OCA\Dossiq\Service\Notice\CaseActNotices
 */
class CaseTaskCancelGoldenTest extends TestCase {
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
	public function testCancelAcrossWorlds(): void {
		$task = ['id' => 'engine-task-7', 'objectUuid' => 'case-1', 'title' => 'Check the plan'];
		$worlds = [
			'not signed in' => ['user' => false, 'task' => $task, 'access' => true, 'cancel' => 'ok'],
			'task engine unreachable' => ['user' => true, 'task' => 'throw', 'access' => true, 'cancel' => 'ok'],
			'task not found' => ['user' => true, 'task' => null, 'access' => true, 'cancel' => 'ok'],
			'task without a case' => ['user' => true, 'task' => ['id' => 'task-1'], 'access' => true, 'cancel' => 'ok'],
			'task with an empty case' => ['user' => true, 'task' => ['id' => 'task-1', 'objectUuid' => ''], 'access' => true, 'cancel' => 'ok'],
			'no write access on the case' => ['user' => true, 'task' => $task, 'access' => false, 'cancel' => 'ok'],
			'cancelled' => ['user' => true, 'task' => $task, 'access' => true, 'cancel' => 'ok'],
			'cancelled, task without its own id' => ['user' => true, 'task' => ['objectUuid' => 'case-1'], 'access' => true, 'cancel' => 'ok'],
			'engine refuses with a reason' => ['user' => true, 'task' => $task, 'access' => true, 'cancel' => 'refused: the task is already done'],
			'engine refuses without a reason' => ['user' => true, 'task' => $task, 'access' => true, 'cancel' => 'refused'],
			'engine breaks otherwise' => ['user' => true, 'task' => $task, 'access' => true, 'cancel' => 'logic'],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			try {
				$response = $this->controllerFor(world: $world)->cancel('task-1');
				$outcome = ['status' => $response->getStatus(), 'body' => $response->getData()];
			} catch (Throwable $e) {
				$outcome = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
			}

			$record[$name] = $outcome + ['calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'case-task-cancel', value: $record);
	}//end testCancelAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CaseTaskCancelController
	 */
	private function controllerFor(array $world): CaseTaskCancelController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'reason') ? 'not needed' : $default
		);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId, IUser $user) use ($world): bool {
				$this->calls[] = 'access ' . $caseId . ' ' . $user->getUID();
				return $world['access'];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new CaseTaskCancelController(
			request: $request,
			tasks: $this->tasksFor(world: $world),
			caseAccess: $guard,
			notices: new CaseActNotices(l10n: $l10n),
			userSession: $session,
		);
	}//end controllerFor()

	/**
	 * Task actions that record each call and answer per the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CaseTaskActions
	 */
	private function tasksFor(array $world): CaseTaskActions {
		$tasks = $this->createMock(CaseTaskActions::class);
		$tasks->method('find')->willReturnCallback(
			function (string $taskId) use ($world): ?array {
				$this->calls[] = 'find ' . $taskId;
				if ($world['task'] === 'throw') {
					throw new RuntimeException('engine offline');
				}

				return $world['task'];
			}
		);
		$tasks->method('titleOf')->willReturnCallback(
			static fn (array $task): string => (string)($task['title'] ?? 'untitled')
		);
		$tasks->method('cancel')->willReturnCallback(
			function (string $taskId, string $reason, string $actor) use ($world): array {
				$this->calls[] = 'cancel ' . $taskId . ' "' . $reason . '" by ' . $actor;
				if ($world['cancel'] === 'logic') {
					throw new LogicException('engine bug');
				}

				if ($world['cancel'] !== 'ok') {
					throw new RuntimeException($world['cancel']);
				}

				return ['cancelled' => true, 'task' => ['id' => $taskId]];
			}
		);

		return $tasks;
	}//end tasksFor()
}//end class
