<?php

/**
 * Who sees which advice requests in a list, and who reads the bottleneck
 * report: the lists show only requests on cases the caller may read, the
 * report is for administrators, coordinators and the background service
 * account.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consultation-management/spec.md#requirement-a-consultation-list-shows-only-requests-on-cases-the-caller-may-read
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\ConsultationController;
use OCA\Dossiq\Controller\ConsultationReportController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\CaseRebindGate;
use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\Consultation\ConsultationReports;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The consultation lists and report, by caller.
 */
class ConsultationListAccessTest extends TestCase {

	/**
	 * Two advice requests on two cases.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const REQUESTS = [
		['id' => 'cn-1', 'parentCase' => 'case-mine'],
		['id' => 'cn-2', 'parentCase' => 'case-other'],
	];

	/**
	 * A guard for this caller.
	 *
	 * @param string|null           $uid        The signed-in user, or null for nobody.
	 * @param array<string, bool>   $readable   Case id => whether the case pages let this caller read it.
	 * @param array<int, string>    $groups     The caller's groups.
	 * @param bool                  $admin      Whether the caller is an administrator.
	 * @param string                $serviceUid The configured background service account.
	 * @param ConsultationService|null $service The consultation service, for a controller.
	 *
	 * @return ConsultationAccessGuard
	 */
	private function guard(?string $uid, array $readable = [], array $groups = [], bool $admin = false, string $serviceUid = 'svc-dossiq', ?ConsultationService $service = null): ConsultationAccessGuard {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => ($who === $uid && in_array($group, $groups, true) === true)
		);
		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->method('hasCaseReadAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => ($readable[$caseId] ?? false)
		);
		$serviceAccount = $this->createMock(BackgroundServiceAccount::class);
		$serviceAccount->method('configuredUserId')->willReturn($serviceUid);

		return new ConsultationAccessGuard(
			request: $this->createMock(IRequest::class),
			consultationService: ($service ?? $this->createMock(ConsultationService::class)),
			userSession: $session,
			groupManager: $groupManager,
			caseAccess: $caseAccess,
			serviceAccount: $serviceAccount,
		);
	}//end guard()

	/**
	 * A reader without access to either case gets nothing.
	 *
	 * @return void
	 */
	public function testAReaderWithoutCaseAccessSeesNoRequest(): void {
		$filter = $this->guard('ada')->readableFilter();
		self::assertSame([], array_values(array_filter(self::REQUESTS, $filter)));
	}//end testAReaderWithoutCaseAccessSeesNoRequest()

	/**
	 * A reader sees only the requests on the cases they may read.
	 *
	 * @return void
	 */
	public function testAReaderSeesOnlyRequestsOnCasesTheyMayRead(): void {
		$filter = $this->guard('lars', ['case-mine' => true])->readableFilter();
		self::assertSame(['cn-1'], array_column(array_filter(self::REQUESTS, $filter), 'id'));
	}//end testAReaderSeesOnlyRequestsOnCasesTheyMayRead()

	/**
	 * Without a session nothing is readable, and a request without a case never is.
	 *
	 * @return void
	 */
	public function testNoSessionAndNoCaseMeanNothing(): void {
		self::assertFalse(($this->guard(null)->readableFilter())(self::REQUESTS[0]));
		self::assertFalse(($this->guard('lars', ['' => true])->readableFilter())(['id' => 'cn-3', 'parentCase' => '']));
	}//end testNoSessionAndNoCaseMeanNothing()

	/**
	 * The background service account sees every request, but only as the
	 * configured account in its group: a user who only shares its name or
	 * only its group does not.
	 *
	 * @return void
	 */
	public function testTheServiceAccountSeesAllAndNobodyElseDoesByLookingLikeIt(): void {
		$all = $this->guard('svc-dossiq', [], [BackgroundServiceAccount::GROUP])->readableFilter();
		self::assertCount(2, array_filter(self::REQUESTS, $all));
		$ungrouped = $this->guard('svc-dossiq')->readableFilter();
		self::assertCount(0, array_filter(self::REQUESTS, $ungrouped));
		$grouped = $this->guard('mallory', [], [BackgroundServiceAccount::GROUP])->readableFilter();
		self::assertCount(0, array_filter(self::REQUESTS, $grouped));
		$unset = $this->guard('svc-dossiq', [], [BackgroundServiceAccount::GROUP], false, '')->readableFilter();
		self::assertCount(0, array_filter(self::REQUESTS, $unset));
	}//end testTheServiceAccountSeesAllAndNobodyElseDoesByLookingLikeIt()

	/**
	 * The bottleneck report: a plain user is refused, anonymous is 401,
	 * coordinators, administrators and the service account pass.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-the-bottleneck-report-is-for-coordinators
	 *
	 * @return void
	 */
	public function testTheBottleneckReportIsForCoordinators(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->guard('ada')->requireCoordinator()?->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->guard(null)->requireCoordinator()?->getStatus());
		self::assertNull($this->guard('anja', [], [CaseRebindGate::COORDINATOR_GROUP])->requireCoordinator());
		self::assertNull($this->guard('root', [], [], true)->requireCoordinator());
		self::assertNull($this->guard('svc-dossiq', [], [BackgroundServiceAccount::GROUP])->requireCoordinator());
	}//end testTheBottleneckReportIsForCoordinators()

	/**
	 * Through the controller: a plain user is refused the report before it
	 * is computed, and the lists are asked with the caller's filter.
	 *
	 * @return void
	 */
	public function testTheControllerChecksBeforeItBuildsAnything(): void {
		$service = $this->createMock(ConsultationService::class);
		$service->expects($this->once())->method('getOverdueConsultations')
			->with($this->callback(static fn ($filter): bool => is_callable($filter) === true && $filter(self::REQUESTS[0]) === true && $filter(self::REQUESTS[1]) === false))
			->willReturn([]);
		$reports = $this->createMock(ConsultationReports::class);
		$reports->expects($this->never())->method('getBottlenecks');
		$reports->expects($this->once())->method('getDueSoonConsultations')
			->with($this->callback(static fn ($filter): bool => is_callable($filter) === true && $filter(self::REQUESTS[1]) === false))
			->willReturn(['results' => [], 'warningOffsetDays' => 5]);
		$guard = $this->guard('lars', ['case-mine' => true], [], false, 'svc-dossiq', $service);
		$controller = new ConsultationController(
			appName: Application::APP_ID,
			request: $this->createMock(IRequest::class),
			consultationService: $service,
			accessGuard: $guard,
		);
		$reportController = new ConsultationReportController(
			appName: Application::APP_ID,
			request: $this->createMock(IRequest::class),
			reports: $reports,
			accessGuard: $guard,
		);

		self::assertSame(Http::STATUS_FORBIDDEN, $reportController->bottlenecks()->getStatus());
		self::assertSame(Http::STATUS_OK, $reportController->dueSoon()->getStatus());
		self::assertSame(Http::STATUS_OK, $controller->overdue()->getStatus());
	}//end testTheControllerChecksBeforeItBuildsAnything()
}//end class
