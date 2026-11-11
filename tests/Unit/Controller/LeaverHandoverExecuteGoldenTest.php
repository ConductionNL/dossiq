<?php

/**
 * Golden record of executing a leaver's handover.
 *
 * Records, per world, the status and body `execute` answers with and every
 * call it makes, so a refactor cannot change who may hand over somebody's work
 * or what reaches the handover service.
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

use InvalidArgumentException;
use OCA\Dossiq\Controller\LeaverHandoverController;
use OCA\Dossiq\Service\People\LeaverHandoverService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for LeaverHandoverController::execute.
 *
 * @covers \OCA\Dossiq\Controller\LeaverHandoverController
 */
class LeaverHandoverExecuteGoldenTest extends TestCase {
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
	public function testExecuteAcrossWorlds(): void {
		$params = ['fromUser' => 'leaver', 'toUser' => 'stayer'];
		$worlds = [
			'not signed in' => [$params, 'none', 'ok'],
			'not an administrator' => [$params, 'clerk', 'ok'],
			'handed over' => [$params, 'admin', 'ok'],
			'handed over without names' => [[], 'admin', 'ok'],
			'incomplete' => [$params, 'admin', 'invalid'],
			'broke' => [$params, 'admin', 'throw'],
		];

		$record = [];
		foreach ($worlds as $name => [$request, $who, $outcome]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $request, world: ['who' => $who, 'outcome' => $outcome])->execute();
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'leaver-handover-execute', value: $record);
	}//end testExecuteAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return LeaverHandoverController
	 */
	private function controllerFor(array $params, array $world): LeaverHandoverController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($world['who']);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['who'] === 'none' ? null : $user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$handover = $this->createMock(LeaverHandoverService::class);
		$handover->method('execute')->willReturnCallback(
			function (string $fromUser, string $toUser, string $actor) use ($world): array {
				$this->calls[] = 'execute ' . json_encode([$fromUser, $toUser, $actor]);
				if ($world['outcome'] === 'invalid') {
					throw new InvalidArgumentException('fromUser is required');
				}

				if ($world['outcome'] === 'throw') {
					throw new RuntimeException('register offline');
				}

				return ['moved' => 4];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'error: ' . $message . ' ' . json_encode($context);
			}
		);

		return new LeaverHandoverController('dossiq', $request, $handover, $groups, $session, $logger);
	}//end controllerFor()
}//end class
