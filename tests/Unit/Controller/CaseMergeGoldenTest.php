<?php

/**
 * Golden record of the case merge endpoint.
 *
 * Records, per world, the status and body `merge` answers with and every call
 * it makes, so a refactor cannot change who may merge two cases or how a
 * refusal reads.
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

use OCA\Dossiq\Controller\CaseMergeController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseMergeService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CaseMergeController::merge.
 *
 * @covers \OCA\Dossiq\Controller\CaseMergeController
 */
class CaseMergeGoldenTest extends TestCase {
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
	public function testMergeAcrossWorlds(): void {
		$allowed = ['user' => true, 'access' => ['case-a' => true, 'case-b' => true], 'outcome' => ['operation' => ['id' => 'op-1']]];
		$worlds = [
			'not signed in' => ['user' => false] + $allowed,
			'no access to the merged case' => ['access' => ['case-a' => false, 'case-b' => true]] + $allowed,
			'no access to the surviving case' => ['access' => ['case-a' => true, 'case-b' => false]] + $allowed,
			'merged' => $allowed,
			'merged without an operation' => ['outcome' => []] + $allowed,
		];
		foreach (['same-case', 'unknown-case', 'final-status', 'signed-beschikking', 'already-merged', 'survivor-already-merged', 'platform-unavailable', 'platform-refused', 'something-new'] as $rule) {
			$worlds['refused: ' . $rule] = ['outcome' => ['refused' => $rule]] + $allowed;
		}

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			$response = $this->controllerFor(world: $world)->merge('case-a', 'case-b', 'duplicate');
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'case-merge', value: $record);
	}//end testMergeAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CaseMergeController
	 */
	private function controllerFor(array $world): CaseMergeController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseMutationAccess ' . $caseId;
				return $world['access'][$caseId] ?? false;
			}
		);

		$merge = $this->createMock(CaseMergeService::class);
		$merge->method('requestMerge')->willReturnCallback(
			function (string $mergedId, string $survivorId, string $reason, string $actor) use ($world): array {
				$this->calls[] = 'requestMerge ' . json_encode([$mergedId, $survivorId, $reason, $actor]);
				return $world['outcome'];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new CaseMergeController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			mergeService: $merge,
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controllerFor()
}//end class
