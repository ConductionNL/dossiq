<?php

/**
 * SubstitutionController list, create and revoke tests.
 *
 * The access guard is the real one, over a session, a group manager and a
 * settings service whose object service is a recording double, so who may
 * register or revoke a substitution is decided by production code. The
 * substitution service and the logger are mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\SubstitutionController;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard;
use OCA\Dossiq\Service\SubstitutionAuditService;
use OCA\Dossiq\Service\SubstitutionService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\SubstitutionController
 * @uses   \OCA\Dossiq\Service\Substitution\SubstitutionAccessGuard
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class SubstitutionControllerWriteTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The substitution service.
	 *
	 * @var SubstitutionService&MockObject
	 */
	private SubstitutionService&MockObject $substitutions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The substitution rows the object service holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Build the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->params        = [];
		$this->rows          = [];
		$this->substitutions = $this->createMock(SubstitutionService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The controller for a signed-in user, coordinator or not.
	 *
	 * @param string $uid         The user; '' for no session.
	 * @param bool   $coordinator Whether the user is a coordinator.
	 *
	 * @return SubstitutionController
	 */
	private function controllerFor(string $uid, bool $coordinator = false): SubstitutionController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== '') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $who): bool => $coordinator === true && $who === $uid);

		$rows    = &$this->rows;
		$objects = new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows The stored rows.
			 */
			public function __construct(private array &$rows) {
			}

			public function find(string $id, string $register, string $schema): ?array {
				foreach ($this->rows as $row) {
					if (($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->rows;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['substitution_schema', '', 'substitution'],
			]
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);

		return new SubstitutionController(
			request: $request,
			substitutionService: $this->substitutions,
			auditService: $this->createMock(SubstitutionAuditService::class),
			accessGuard: new SubstitutionAccessGuard(settingsService: $settings, userSession: $session, groupManager: $groups),
			reassignmentService: $this->createMock(CaseReassignmentService::class),
			logger: $this->logger,
		);

	}//end controllerFor()

	public function testIndexShowsAUserOnlyTheSubstitutionsTheyAreIn(): void {
		$this->rows = [
			['id' => 's1', 'absentee' => 'anna', 'substitute' => 'bas'],
			['id' => 's2', 'absentee' => 'carla', 'substitute' => 'anna'],
			['id' => 's3', 'absentee' => 'dirk', 'substitute' => 'erik'],
		];

		$this->assertSame(['results' => [$this->rows[0], $this->rows[1]]], $this->controllerFor('anna')->index()->getData());
		$this->assertCount(3, $this->controllerFor('coordinator', true)->index()->getData()['results']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controllerFor('')->index()->getStatus());

	}//end testIndexShowsAUserOnlyTheSubstitutionsTheyAreIn()

	public function testCreateRegistersTheCallersOwnAbsenceWithTheDefaults(): void {
		$this->params = ['substitute' => 'bas', 'startDate' => '2026-12-01', 'endDate' => '2026-12-14'];
		$this->substitutions->expects($this->once())
			->method('create')
			->with('anna', 'bas', '2026-12-01', '2026-12-14', 'all', [], 'verlof', 'anna', '')
			->willReturn(['id' => 's9', 'absentee' => 'anna']);

		$response = $this->controllerFor('anna')->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 's9', 'absentee' => 'anna'], $response->getData());

	}//end testCreateRegistersTheCallersOwnAbsenceWithTheDefaults()

	public function testOnlyACoordinatorRegistersAnotherUsersAbsence(): void {
		$this->params = ['absentee' => 'carla', 'substitute' => 'bas'];
		$this->substitutions->expects($this->once())->method('create')
			->with('carla', 'bas', '', '', 'all', [], 'verlof', 'coordinator', '')
			->willReturn(['id' => 's10']);

		$refused = $this->controllerFor('anna')->create();
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['error' => 'You may only register a substitution for yourself'], $refused->getData());

		$this->assertSame(Http::STATUS_CREATED, $this->controllerFor('coordinator', true)->create()->getStatus());

	}//end testOnlyACoordinatorRegistersAnotherUsersAbsence()

	public function testCreateWithoutASessionIsRefusedBeforeTheService(): void {
		$this->substitutions->expects($this->never())->method('create');

		$this->assertSame(['error' => 'Not authenticated'], $this->controllerFor('')->create()->getData());

	}//end testCreateWithoutASessionIsRefusedBeforeTheService()

	public function testARefusedOrFailingCreateSaysWhy(): void {
		$this->substitutions->method('create')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('endDate before startDate')),
			$this->throwException(new RuntimeException('store down'))
		);
		$this->logger->expects($this->once())->method('error')->with('Substitution create failed', ['error' => 'store down']);
		$controller = $this->controllerFor('anna');

		$invalid = $controller->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $invalid->getStatus());
		$this->assertSame(['error' => 'endDate before startDate'], $invalid->getData());

		$failed = $controller->create();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'store down'], $failed->getData());

	}//end testARefusedOrFailingCreateSaysWhy()

	public function testRevokingAnUnknownSubstitutionIs404(): void {
		$this->substitutions->expects($this->never())->method('revoke');

		$response = $this->controllerFor('anna')->revoke('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Substitution not found'], $response->getData());

	}//end testRevokingAnUnknownSubstitutionIs404()

	public function testOnlyTheOwnerOrACoordinatorRevokes(): void {
		$this->rows = [['id' => 's1', 'absentee' => 'carla', 'createdBy' => 'carla', 'substitute' => 'anna']];
		$this->substitutions->expects($this->exactly(2))->method('revoke')->with('s1')
			->willReturnOnConsecutiveCalls(['id' => 's1', 'status' => 'revoked-by-owner'], null);

		$refused = $this->controllerFor('anna')->revoke('s1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		$this->assertSame(['error' => 'You may only revoke your own substitution'], $refused->getData());

		$this->assertSame(['id' => 's1', 'status' => 'revoked-by-owner'], $this->controllerFor('carla')->revoke('s1')->getData());
		$this->assertSame(['status' => 'revoked'], $this->controllerFor('coordinator', true)->revoke('s1')->getData());

	}//end testOnlyTheOwnerOrACoordinatorRevokes()

	public function testARefusedOrFailingRevokeSaysWhy(): void {
		$this->rows = [['id' => 's1', 'absentee' => 'anna']];
		$this->substitutions->method('revoke')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('already revoked')),
			$this->throwException(new RuntimeException('store down'))
		);
		$this->logger->expects($this->once())->method('error')->with('Substitution revoke failed', ['error' => 'store down']);
		$controller = $this->controllerFor('anna');

		$invalid = $controller->revoke('s1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $invalid->getStatus());
		$this->assertSame(['error' => 'already revoked'], $invalid->getData());

		$failed = $controller->revoke('s1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'store down'], $failed->getData());

	}//end testARefusedOrFailingRevokeSaysWhy()
}//end class
