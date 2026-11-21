<?php

/**
 * Golden record of creating a federated case share.
 *
 * Records, per world, the status and body `createFederatedShare` answers with
 * and every call it makes, so a refactor cannot change who may share a case
 * with a remote organisation or what reaches the sharing service.
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseFederationController;
use OCA\Dossiq\Service\CaseCollaborationService;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\CaseTransferService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for CaseFederationController::createFederatedShare.
 *
 * @covers \OCA\Dossiq\Controller\CaseFederationController
 */
class FederatedShareCreateGoldenTest extends TestCase {
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
	public function testCreateAcrossWorlds(): void {
		$full = ['caseId' => 'case-1', 'remoteCloudId' => 'bob@remote.example', 'sharedFields' => ['title'], 'sharedDocuments' => 'doc-1', 'permissionLevel' => 'bewerken'];
		$minimal = ['caseId' => 'case-1', 'remoteCloudId' => 'bob@remote.example'];
		$allowed = ['user' => true, 'access' => true, 'share' => 'ok'];
		$worlds = [
			'not signed in' => [$full, ['user' => false] + $allowed],
			'no case' => [['remoteCloudId' => 'bob@remote.example'], $allowed],
			'no remote' => [['caseId' => 'case-1'], $allowed],
			'case id "0"' => [['caseId' => '0', 'remoteCloudId' => 'bob@remote.example'], $allowed],
			'no access to the case' => [$full, ['access' => false] + $allowed],
			'shared with every option' => [$full, $allowed],
			'shared with the defaults' => [$minimal, $allowed],
			'the remote refuses' => [$full, ['share' => 'error'] + $allowed],
		];

		$record = [];
		foreach ($worlds as $name => [$params, $world]) {
			$this->calls = [];
			$response = $this->controllerFor(params: $params, world: $world)->createFederatedShare();
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'federated-share-create', value: $record);
	}//end testCreateAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return CaseFederationController
	 */
	private function controllerFor(array $params, array $world): CaseFederationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $user : null);

		$sharing = $this->createMock(CaseSharingService::class);
		$sharing->method('canUserAccessCase')->willReturnCallback(
			function (string $caseId, string $userId) use ($world): bool {
				$this->calls[] = 'canUserAccessCase ' . $caseId . ' ' . $userId;
				return $world['access'];
			}
		);
		$sharing->method('createFederatedShare')->willReturnCallback(
			function (...$args) use ($world): array {
				$this->calls[] = 'createFederatedShare ' . json_encode($args);
				if ($world['share'] === 'error') {
					return ['error' => 'The remote server refused the share'];
				}

				return ['id' => 'fed-1'];
			}
		);

		return new CaseFederationController(
			request: $request,
			caseSharingService: $sharing,
			caseTransferService: $this->createMock(CaseTransferService::class),
			collabService: $this->createMock(CaseCollaborationService::class),
			userSession: $session,
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controllerFor()
}//end class
