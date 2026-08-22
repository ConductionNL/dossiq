<?php

/**
 * AdviceService authorization tests (procest#17 IDOR — live path).
 *
 * The procest#17 / Wilco #6 IDOR guard originally landed on
 * `AdviceService::submitAdvice()`, which had ZERO callers and was never routed.
 * The live path — `POST /api/advice/{id}/transition` ->
 * `AdviceController::transitionStatus()` -> `AdviceService::transitionStatus()`
 * — carried no check at all, so the IDOR stayed open. These tests pin the guard
 * to the LIVE path and prove the BAD path is rejected.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Typed stub for the OpenRegister ObjectService.
 *
 * AdviceService calls find()/saveObject() with named arguments; a bare
 * addMethods() magic mock rejects named arguments with "Unknown named
 * parameter", so a typed interface is required here.
 */
interface AdviceObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * AdviceService reaches find() two ways — `find($id, register: ..., schema: ...)`
	 * (loadAdvice) and `find(id: ..., register: ..., schema: ...)` (the
	 * SearchesObjects trait). A variadic `...$args` tail cannot accept those
	 * named arguments, and the resulting Error is swallowed by loadAdvice's
	 * `catch (Throwable)` into a silent null — so the params are declared
	 * explicitly here.
	 *
	 * @param int|string $id Object UUID.
	 * @param mixed $register Register slug.
	 * @param mixed $schema Schema slug.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;

	/**
	 * Save or update an object.
	 *
	 * @param array<string,mixed> $object Object data.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string|null $uuid Optional object UUID for updates.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array;
}//end interface

/**
 * Unit tests for AdviceService transition authorization.
 *
 * @covers \OCA\Dossiq\Service\AdviceService
 *
 * @uses \OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard
 * @uses \OCA\Dossiq\Service\Advice\AdviceNotifier
 * @uses \OCA\Dossiq\Service\Advice\AdviceRepository
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class AdviceServiceAuthorizationTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * @var AdviceObjectServiceStub|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var AdviceService
	 */
	private AdviceService $service;

	/**
	 * The advice record under test: assigned to adviseur `alice`, linked to a
	 * case handled by `bob`.
	 *
	 * @var array<string, mixed>
	 */
	private array $advice = [
		'id' => 'advice-1',
		'advisor' => 'alice',
		'case' => 'case-1',
		'subject' => 'Kapvergunning',
		'status' => 'requested',
	];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->objectService = $this->createMock(AdviceObjectServiceStub::class);

		$this->settingsService->method('getObjectService')->willReturn($this->objectService);
		// getConfigValue(string $key, string $default = '') is called with a
		// single argument here; a callback sidesteps willReturnMap's arity
		// matching entirely.
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return [
					'register' => 'procest',
					'advies_aanvraag_schema' => 'adviesAanvraag',
					'case_schema' => 'case',
				][$key] ?? '';
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new AdviceService(
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			logger: $logger,
			adviceDelegation: $this->createMock(AdviceDelegationService::class),
			repository: new AdviceRepository(
				settingsService: $this->settingsService,
				logger: $logger,
			),
			guard: new AdviceAuthorizationGuard(
				settingsService: $this->settingsService,
				userSession: $this->userSession,
				groupManager: $this->groupManager,
			),
			notifier: new AdviceNotifier(
				notificationManager: $this->createMock(INotificationManager::class),
				logger: $logger,
			),
		);
	}//end setUp()

	/**
	 * Sign in as the given uid (or nobody when null).
	 *
	 * @param string|null $uid The user id, or null for no session.
	 * @param bool $isAdmin Whether the user is an admin.
	 *
	 * @return void
	 */
	private function signInAs(?string $uid, bool $isAdmin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end signInAs()

	/**
	 * Make find() return the advice record (and the case when asked).
	 *
	 * @return void
	 */
	private function stubFind(): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) {
				if ((string)$id === 'case-1') {
					return ['id' => 'case-1', 'assignee' => 'bob'];
				}

				return $this->advice;
			}
		);
	}//end stubFind()

	/**
	 * THE IDOR. An authenticated user who is neither the adviseur, nor the
	 * handler of the linked case, nor an admin, must NOT be able to mark
	 * someone else's advice request as received on the LIVE path.
	 *
	 * @return void
	 */
	public function testUnrelatedUserIsRejectedFromMarkingAdviceReceived(): void {
		$this->signInAs('mallory');
		$this->stubFind();

		// The bad path must never reach a write.
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Advice request not accessible');

		$this->service->transitionStatus('advice-1', 'received');
	}//end testUnrelatedUserIsRejectedFromMarkingAdviceReceived()

	/**
	 * The assigned adviseur is still allowed (no functional regression).
	 *
	 * @return void
	 */
	public function testAssignedAdviseurMayMarkAdviceReceived(): void {
		$this->signInAs('alice');
		$this->stubFind();

		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'advice-1', 'status' => 'received']);

		$result = $this->service->transitionStatus('advice-1', 'received');

		$this->assertSame('received', $result['status']);
	}//end testAssignedAdviseurMayMarkAdviceReceived()

	/**
	 * Admins bypass per-object checks.
	 *
	 * @return void
	 */
	public function testAdminMayMarkAdviceReceived(): void {
		$this->signInAs('root', isAdmin: true);
		$this->stubFind();

		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'advice-1', 'status' => 'received']);

		$result = $this->service->transitionStatus('advice-1', 'received');

		$this->assertSame('received', $result['status']);
	}//end testAdminMayMarkAdviceReceived()

	/**
	 * The handler of the linked case may request/notify advice.
	 *
	 * @return void
	 */
	public function testCaseHandlerMayRequestAdvice(): void {
		$this->signInAs('bob');
		$this->stubFind();

		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'advice-1', 'status' => 'requested']);

		$result = $this->service->transitionStatus('advice-1', 'requested');

		$this->assertSame('requested', $result['status']);
	}//end testCaseHandlerMayRequestAdvice()

	/**
	 * An unrelated user cannot fire the `aangevraagd` transition either.
	 *
	 * @return void
	 */
	public function testUnrelatedUserIsRejectedFromRequestingAdvice(): void {
		$this->signInAs('mallory');
		$this->stubFind();

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->service->transitionStatus('advice-1', 'requested');
	}//end testUnrelatedUserIsRejectedFromRequestingAdvice()

	/**
	 * `verlopen` is a system-only transition and must be unreachable over the
	 * HTTP path — even for the adviseur.
	 *
	 * @return void
	 */
	public function testExpiryTransitionIsRejectedOverTheHttpPath(): void {
		$this->signInAs('alice');
		$this->stubFind();

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->service->transitionStatus('advice-1', 'expired');
	}//end testExpiryTransitionIsRejectedOverTheHttpPath()

	/**
	 * Guard regression: the deadline cron runs with NO user session and must
	 * still be able to expire advice. A guard that required a session here
	 * would silently break AdviceDeadlineJob.
	 *
	 * @return void
	 */
	public function testExpireAdviceStillWorksWithoutASession(): void {
		$this->signInAs(null);
		$this->stubFind();

		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn(['id' => 'advice-1', 'status' => 'expired']);

		$result = $this->service->expireAdvice('advice-1');

		$this->assertSame('expired', $result['status']);
	}//end testExpireAdviceStillWorksWithoutASession()

	/**
	 * An unauthenticated caller is rejected on the HTTP path.
	 *
	 * @return void
	 */
	public function testUnauthenticatedCallerIsRejected(): void {
		$this->signInAs(null);
		$this->stubFind();

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Not authenticated');

		$this->service->transitionStatus('advice-1', 'received');
	}//end testUnauthenticatedCallerIsRejected()

	/**
	 * An invalid status is rejected before anything else happens.
	 *
	 * @return void
	 */
	public function testInvalidStatusIsRejected(): void {
		$this->signInAs('alice');

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid advice status');

		$this->service->transitionStatus('advice-1', 'bogus');
	}//end testInvalidStatusIsRejected()

	/**
	 * A missing advice record collapses to "not accessible" so the endpoint is
	 * not an existence oracle for advice UUIDs.
	 *
	 * @return void
	 */
	public function testMissingAdviceCollapsesToNotAccessible(): void {
		$this->signInAs('mallory');
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Advice request not accessible');

		$this->service->transitionStatus('advice-does-not-exist', 'received');
	}//end testMissingAdviceCollapsesToNotAccessible()
}//end class
