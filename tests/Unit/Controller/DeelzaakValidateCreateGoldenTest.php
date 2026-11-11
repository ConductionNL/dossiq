<?php

/**
 * Golden record of validating and creating a sub-case.
 *
 * Records, per world, the status and body `validate` and `create` answer with
 * and every call they make, so a refactor cannot change who may hang a
 * sub-case off a parent or how each outcome is answered.
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
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DeelzaakController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DeelzaakService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DeelzaakController::validate and ::create.
 *
 * @covers \OCA\Dossiq\Controller\DeelzaakController
 */
class DeelzaakValidateCreateGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the two endpoints distinguish, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testValidateAndCreateAcrossWorlds(): void {
		$full = ['parentCaseUuid' => 'parent-1', 'childCaseTypeId' => 'type-2', 'object' => ['title' => 'Deel']];
		$worlds = [
			'not signed in' => [$full, false, true, ['ok' => true]],
			'no parent' => [['childCaseTypeId' => 'type-2', 'object' => []], true, true, ['ok' => true]],
			'no child type' => [['parentCaseUuid' => 'parent-1', 'object' => []], true, true, ['ok' => true]],
			'an object that is not a list' => [['object' => 'x'] + $full, true, true, ['ok' => true]],
			'no read access on the parent' => [$full, true, false, ['ok' => true]],
			'the service refuses' => [$full, true, true, ['ok' => false, 'reason' => 'type-not-allowed']],
			'allowed' => [$full, true, true, ['ok' => true, 'caseId' => 'child-1']],
		];

		$record = [];
		foreach ($worlds as $name => [$params, $signedIn, $mayRead, $answer]) {
			foreach (['validate', 'create'] as $endpoint) {
				$this->calls = [];
				$response = $this->controllerFor(params: $params, world: ['user' => $signedIn, 'read' => $mayRead, 'answer' => $answer])->$endpoint();
				$record[$name][$endpoint] = ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $this->calls];
			}
		}

		$this->assertMatchesGolden(name: 'deelzaak-validate-create', value: $record);
	}//end testValidateAndCreateAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array<string, mixed> $world  The world.
	 *
	 * @return DeelzaakController
	 */
	private function controllerFor(array $params, array $world): DeelzaakController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($world['user'] === true ? $this->createMock(IUser::class) : null);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			function (string $caseId) use ($world): bool {
				$this->calls[] = 'hasCaseReadAccess ' . $caseId;
				return $world['read'];
			}
		);

		$service = $this->createMock(DeelzaakService::class);
		$service->method('validateCreate')->willReturnCallback(
			function (string $parentCaseUuid, string $childCaseTypeId) use ($world): array {
				$this->calls[] = 'validateCreate ' . json_encode([$parentCaseUuid, $childCaseTypeId]);
				return $world['answer'];
			}
		);
		$service->method('createSubCase')->willReturnCallback(
			function (string $parentCaseUuid, string $childCaseTypeId, array $childData) use ($world): array {
				$this->calls[] = 'createSubCase ' . json_encode([$parentCaseUuid, $childCaseTypeId, $childData]);
				return $world['answer'];
			}
		);

		return new DeelzaakController($request, $service, $session, $guard);
	}//end controllerFor()
}//end class
