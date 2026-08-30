<?php

/**
 * ComplaintCategoryController Wire-Contract Tests
 *
 * Contract coverage for the two write endpoints on the complaint-category
 * reference list (gate-25). Both are `@NoAdminRequired`, so the ONLY thing
 * standing between any authenticated user and the klachtcategorie vocabulary —
 * which drives complaint routing and the statutory Awb reporting — is
 * `ComplaintAccessGuard::requireCoordinator()`. That guard is therefore
 * exercised for real here (a real ComplaintAccessGuard over mocked NC
 * collaborators), not stubbed away: a mocked guard would answer "allowed" no
 * matter what the controller asked it.
 *
 * The contract pinned here:
 *
 *  - no session answers 401 and never reaches OpenRegister;
 *  - a non-admin is refused with OCSForbiddenException (rendered 403 by the NC
 *    middleware) and the admin question is asked about the CALLER's uid;
 *  - an absent OpenRegister answers 503 — not an empty 200 a client would read
 *    as "no categories saved";
 *  - create answers 201 and passes NO uuid, update answers 200 and passes the
 *    path id as `uuid` — an update that forgets the uuid silently forks a
 *    duplicate category instead of editing one, which is the realistic defect
 *    on two near-identical methods.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ComplaintCategoryController;
use OCA\Dossiq\Service\Complaint\ComplaintAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Minimal ObjectService surface for the ComplaintCategoryController tests.
 *
 * The controller calls `saveObject()` with NAMED arguments, so the stub must
 * declare the real parameter names (`object`, `register`, `schema`, `uuid`).
 * Prefixed with the controller name because other contract-test files declare
 * their own stubs in this same namespace.
 */
interface ComplaintCategoryControllerObjectServiceStub {

	/**
	 * Save or update an object in a register/schema.
	 *
	 * @param array<string, mixed> $object The object payload.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string|null $uuid The uuid to update, or null to create.
	 *
	 * @return mixed The saved object.
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): mixed;
}//end interface

/**
 * Wire-contract tests for ComplaintCategoryController.
 *
 * @covers \OCA\Dossiq\Controller\ComplaintCategoryController
 *
 * @uses \OCA\Dossiq\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintCategoryControllerContractTest extends TestCase {

	/**
	 * The IRequest mock.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The SettingsService mock.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The IUserSession mock backing the real access guard.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The IGroupManager mock backing the real access guard.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The REAL access guard under test alongside the controller.
	 *
	 * @var ComplaintAccessGuard
	 */
	private ComplaintAccessGuard $accessGuard;

	/**
	 * The controller under test.
	 *
	 * @var ComplaintCategoryController
	 */
	private ComplaintCategoryController $controller;

	/**
	 * Build the controller over a real ComplaintAccessGuard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->accessGuard = new ComplaintAccessGuard(
			request: $this->request,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);

		$this->controller = new ComplaintCategoryController(
			appName: 'dossiq',
			request: $this->request,
			settingsService: $this->settingsService,
			accessGuard: $this->accessGuard,
		);
	}//end setUp()

	/**
	 * Put a signed-in user on the session.
	 *
	 * @param string $uid The UID of the signed-in user.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Wire the register/schema configuration the controller reads.
	 *
	 * @return void
	 */
	private function withConfiguredSchemas(): void {
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq-register',
					'complaint_category_schema' => 'klachtcategorie',
					default => $default,
				};
			}
		);
	}//end withConfiguredSchemas()

	/**
	 * `createCategory` refuses an anonymous caller with 401 and never touches
	 * OpenRegister.
	 *
	 * @return void
	 */
	public function testCreateCategoryRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getObjectService');

		$response = $this->controller->createCategory();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testCreateCategoryRefusesAnUnauthenticatedCallerWith401()

	/**
	 * A plain authenticated user may NOT extend the category vocabulary: the
	 * coordinator guard refuses, and it asks about the caller's own uid.
	 *
	 * @return void
	 */
	public function testCreateCategoryRefusesANonCoordinatorBeforeReachingOpenRegister(): void {
		$this->signIn(uid: 'bob');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('bob')
			->willReturn(false);
		$this->settingsService->expects($this->never())->method('getObjectService');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('This action requires coordinator (admin) privileges');

		$this->controller->createCategory();
	}//end testCreateCategoryRefusesANonCoordinatorBeforeReachingOpenRegister()

	/**
	 * With OpenRegister absent the write answers 503 — a dependency outage, not
	 * a client error and not a silent success.
	 *
	 * @return void
	 */
	public function testCreateCategoryReturns503WhenOpenRegisterIsAbsent(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->createCategory();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister not available'], $response->getData());
	}//end testCreateCategoryReturns503WhenOpenRegisterIsAbsent()

	/**
	 * A coordinator's create answers 201 and writes the posted body into the
	 * configured register/schema WITHOUT a uuid — passing one would overwrite an
	 * existing category instead of adding a new one.
	 *
	 * @return void
	 */
	public function testCreateCategoryReturns201AndWritesWithoutAUuid(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withConfiguredSchemas();
		$this->request->method('getParams')->willReturn(['name' => 'Bejegening']);

		$captured = [];
		$objectService = $this->createMock(ComplaintCategoryControllerObjectServiceStub::class);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (array $object, string $register, string $schema, ?string $uuid = null) use (&$captured): array {
					$captured = [
						'object' => $object,
						'register' => $register,
						'schema' => $schema,
						'uuid' => $uuid,
					];

					return ['id' => 'cat-1', 'name' => $object['name']];
				}
			);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$response = $this->controller->createCategory();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'cat-1', 'name' => 'Bejegening'], $response->getData());
		$this->assertSame(['name' => 'Bejegening'], $captured['object']);
		$this->assertSame('dossiq-register', $captured['register']);
		$this->assertSame('klachtcategorie', $captured['schema']);
		$this->assertNull($captured['uuid'], 'a create must not address an existing uuid');
	}//end testCreateCategoryReturns201AndWritesWithoutAUuid()

	/**
	 * A validation failure raised by the store surfaces as 400 with the store's
	 * own message, not as a 500.
	 *
	 * @return void
	 */
	public function testCreateCategoryReportsAValidationFailureAs400(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withConfiguredSchemas();
		$this->request->method('getParams')->willReturn(['name' => '']);

		$objectService = $this->createMock(ComplaintCategoryControllerObjectServiceStub::class);
		$objectService->method('saveObject')
			->willThrowException(new \RuntimeException('name is required'));
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$response = $this->controller->createCategory();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'name is required'], $response->getData());
	}//end testCreateCategoryReportsAValidationFailureAs400()

	/**
	 * `updateCategory` refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testUpdateCategoryRefusesAnUnauthenticatedCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getObjectService');

		$response = $this->controller->updateCategory(id: 'cat-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testUpdateCategoryRefusesAnUnauthenticatedCallerWith401()

	/**
	 * A plain authenticated user may not rewrite an existing category either.
	 *
	 * @return void
	 */
	public function testUpdateCategoryRefusesANonCoordinatorBeforeReachingOpenRegister(): void {
		$this->signIn(uid: 'bob');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('bob')
			->willReturn(false);
		$this->settingsService->expects($this->never())->method('getObjectService');

		$this->expectException(OCSForbiddenException::class);

		$this->controller->updateCategory(id: 'cat-1');
	}//end testUpdateCategoryRefusesANonCoordinatorBeforeReachingOpenRegister()

	/**
	 * With OpenRegister absent the update answers 503.
	 *
	 * @return void
	 */
	public function testUpdateCategoryReturns503WhenOpenRegisterIsAbsent(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->controller->updateCategory(id: 'cat-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister not available'], $response->getData());
	}//end testUpdateCategoryReturns503WhenOpenRegisterIsAbsent()

	/**
	 * An update addresses the category named in the URL — the path id reaches
	 * the store as `uuid` — and answers 200, not the 201 the create uses.
	 *
	 * @return void
	 */
	public function testUpdateCategoryAddressesThePathIdAndReturns200(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withConfiguredSchemas();
		$this->request->method('getParams')->willReturn(['name' => 'Bejegening (herzien)']);

		$captured = [];
		$objectService = $this->createMock(ComplaintCategoryControllerObjectServiceStub::class);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function (array $object, string $register, string $schema, ?string $uuid = null) use (&$captured): array {
					$captured = [
						'object' => $object,
						'register' => $register,
						'schema' => $schema,
						'uuid' => $uuid,
					];

					return array_merge($object, ['id' => $uuid]);
				}
			);
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$response = $this->controller->updateCategory(id: 'cat-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('cat-42', $captured['uuid'], 'the update must address the category named in the URL');
		$this->assertSame('klachtcategorie', $captured['schema']);
		$this->assertSame('cat-42', $response->getData()['id']);
	}//end testUpdateCategoryAddressesThePathIdAndReturns200()

	/**
	 * A validation failure on update surfaces as 400 with the store's message.
	 *
	 * @return void
	 */
	public function testUpdateCategoryReportsAValidationFailureAs400(): void {
		$this->signIn();
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withConfiguredSchemas();
		$this->request->method('getParams')->willReturn(['name' => '']);

		$objectService = $this->createMock(ComplaintCategoryControllerObjectServiceStub::class);
		$objectService->method('saveObject')
			->willThrowException(new \RuntimeException('unknown category'));
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$response = $this->controller->updateCategory(id: 'cat-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unknown category'], $response->getData());
	}//end testUpdateCategoryReportsAValidationFailureAs400()
}//end class
