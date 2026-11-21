<?php

/**
 * Golden record of the caseload release endpoint.
 *
 * Records, per world, the status and body `releaseCaseload` answers with and
 * every call it makes, so a refactor cannot change who may release a caseload
 * or what reaches the reassignment service.
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
use OCA\Dossiq\Controller\SubstitutionController;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard;
use OCA\Dossiq\Service\SubstitutionAuditService;
use OCA\Dossiq\Service\SubstitutionService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for SubstitutionController::releaseCaseload.
 *
 * @covers \OCA\Dossiq\Controller\SubstitutionController
 */
class SubstitutionReleaseGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the release distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testReleaseAcrossWorlds(): void {
		$params = ['fromUser' => 'leaver', 'toUser' => 'stayer', 'justification' => 'leave'];
		$worlds = [
			'anonymous' => ['uid' => '', 'coordinator' => true, 'params' => $params, 'service' => 'ok'],
			'not a coordinator' => ['uid' => 'clerk', 'coordinator' => false, 'params' => $params, 'service' => 'ok'],
			'released, every case type' => ['uid' => 'boss', 'coordinator' => true, 'params' => $params, 'service' => 'ok'],
			'released, one case type' => ['uid' => 'boss', 'coordinator' => true, 'params' => $params + ['caseType' => ' bezwaar '], 'service' => 'ok'],
			'released, a blank case type' => ['uid' => 'boss', 'coordinator' => true, 'params' => $params + ['caseType' => '   '], 'service' => 'ok'],
			'no parameters' => ['uid' => 'boss', 'coordinator' => true, 'params' => [], 'service' => 'ok'],
			'refused by the service' => ['uid' => 'boss', 'coordinator' => true, 'params' => $params, 'service' => 'invalid'],
			'service breaks' => ['uid' => 'boss', 'coordinator' => true, 'params' => $params, 'service' => 'broken'],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			$response = $this->controllerFor(world: $world)->releaseCaseload();
			$record[$name] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'substitution-release', value: $record);
	}//end testReleaseAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return SubstitutionController
	 */
	private function controllerFor(array $world): SubstitutionController {
		$params = $world['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$guard = $this->createMock(SubstitutionAccessGuard::class);
		$guard->method('currentUid')->willReturn($world['uid']);
		$guard->method('isCoordinator')->willReturnCallback(
			function (string $userId) use ($world): bool {
				$this->calls[] = 'isCoordinator ' . $userId;
				return $world['coordinator'];
			}
		);
		$guard->method('forbidden')->willReturnCallback(
			static fn (string $message = 'Not authorised'): JSONResponse => new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'error: ' . $message . ' ' . json_encode($context);
			}
		);

		return new SubstitutionController(
			request: $request,
			substitutionService: $this->createMock(SubstitutionService::class),
			auditService: $this->createMock(SubstitutionAuditService::class),
			accessGuard: $guard,
			reassignmentService: $this->reassignmentFor(world: $world),
			logger: $logger,
		);
	}//end controllerFor()

	/**
	 * A reassignment service that records the release and answers per the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return CaseReassignmentService
	 */
	private function reassignmentFor(array $world): CaseReassignmentService {
		$service = $this->createMock(CaseReassignmentService::class);
		$service->method('releaseCaseload')->willReturnCallback(
			function (string $fromUser, string $toUser, string $justification, ?array $filter = null, string $actorId = '') use ($world): array {
				$this->calls[] = 'release ' . json_encode([$fromUser, $toUser, $justification, $filter, $actorId]);
				if ($world['service'] === 'invalid') {
					throw new InvalidArgumentException('toUser is required');
				}

				if ($world['service'] === 'broken') {
					throw new RuntimeException('register offline');
				}

				return ['released' => 3];
			}
		);

		return $service;
	}//end reassignmentFor()
}//end class
