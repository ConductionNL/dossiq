<?php

/**
 * Who may act on a consultation, read the coordinator report, and list
 * advice requests.
 *
 * @category Tests
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationAccessGuard
 * @uses   \OCA\Dossiq\Service\Consultation\ConsultationAccess
 */
class ConsultationAccessGuardTest extends TestCase {
	/**
	 * A guard for this caller.
	 *
	 * @param string|null                     $uid        The signed-in user, or null for nobody.
	 * @param list<string>                    $admins     The admin uids.
	 * @param array<string, list<string>>     $groups     Uid => groups.
	 * @param ConsultationService|null        $service    The consultation service.
	 * @param CaseAccessGuard|null            $caseAccess The case access guard.
	 * @param string                          $serviceUid The configured background service account.
	 *
	 * @return ConsultationAccessGuard
	 */
	private function guard(
		?string $uid,
		array $admins = [],
		array $groups = [],
		?ConsultationService $service = null,
		?CaseAccessGuard $caseAccess = null,
		string $serviceUid = '',
	): ConsultationAccessGuard {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $who): bool => in_array($who, $admins, true)
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, ($groups[$who] ?? []), true)
		);

		$serviceAccount = $this->createMock(BackgroundServiceAccount::class);
		$serviceAccount->method('configuredUserId')->willReturn($serviceUid);

		return new ConsultationAccessGuard(
			request: $this->createMock(IRequest::class),
			consultationService: ($service ?? $this->createMock(ConsultationService::class)),
			userSession: $session,
			groupManager: $groupManager,
			caseAccess: ($caseAccess ?? $this->createMock(CaseAccessGuard::class)),
			serviceAccount: $serviceAccount,
		);
	}//end guard()

	/**
	 * A consultation service that returns this consultation.
	 *
	 * @param array<string, mixed>|null $consultation The consultation, or null for not found.
	 *
	 * @return ConsultationService
	 */
	private function serviceReturning(?array $consultation): ConsultationService {
		$service = $this->createMock(ConsultationService::class);
		$service->method('getConsultation')->with('cn-1')->willReturn($consultation);
		return $service;
	}//end serviceReturning()

	/**
	 * Assert a response's status and body.
	 *
	 * @param int                  $status   The expected status.
	 * @param array<string, mixed> $data     The expected body.
	 * @param JSONResponse|null    $response The response.
	 *
	 * @return void
	 */
	private function assertResponse(int $status, array $data, ?JSONResponse $response): void {
		$this->assertNotNull($response);
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}//end assertResponse()

	public function testAuthorizeRefusesAnUnauthenticatedCallerBeforeLoading(): void {
		$service = $this->createMock(ConsultationService::class);
		$service->expects($this->never())->method('getConsultation');

		$access = $this->guard(null, service: $service)->authorize('cn-1');

		$this->assertResponse(Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated'], $access->error);
		$this->assertSame([], $access->consultation);
	}//end testAuthorizeRefusesAnUnauthenticatedCallerBeforeLoading()

	public function testAuthorizeAnswersNotFoundForAnUnknownConsultation(): void {
		$access = $this->guard('alice', service: $this->serviceReturning(null))->authorize('cn-1');

		$this->assertResponse(Http::STATUS_NOT_FOUND, ['error' => 'Consultation not found'], $access->error);
	}//end testAuthorizeAnswersNotFoundForAnUnknownConsultation()

	/**
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function permitted(): array {
		return [
			'the applicant' => ['alice', []],
			'the assignee' => ['bob', []],
			'an administrator' => ['root', ['root']],
		];
	}//end permitted()

	/**
	 * @dataProvider permitted
	 *
	 * @param string       $uid    The caller.
	 * @param list<string> $admins The admin uids.
	 */
	public function testAuthorizeLetsTheApplicantAssigneeAndAdminsThrough(string $uid, array $admins): void {
		$consultation = ['id' => 'cn-1', 'applicant' => 'alice', 'assignee' => 'bob'];

		$access = $this->guard($uid, admins: $admins, service: $this->serviceReturning($consultation))->authorize('cn-1');

		$this->assertNull($access->error);
		$this->assertSame($consultation, $access->consultation);
	}//end testAuthorizeLetsTheApplicantAssigneeAndAdminsThrough()

	public function testAuthorizeRefusesAnyoneElse(): void {
		$consultation = ['id' => 'cn-1', 'applicant' => 'alice', 'assignee' => 'bob'];

		$access = $this->guard('carol', service: $this->serviceReturning($consultation))->authorize('cn-1');

		$this->assertResponse(Http::STATUS_FORBIDDEN, ['error' => 'Access to this consultation is not permitted'], $access->error);
		$this->assertSame([], $access->consultation);
	}//end testAuthorizeRefusesAnyoneElse()

	public function testAnEmptyAssigneeMatchesNobody(): void {
		$consultation = ['id' => 'cn-1', 'applicant' => 'alice', 'assignee' => ''];

		$access = $this->guard('', service: $this->serviceReturning($consultation))->authorize('cn-1');

		$this->assertResponse(Http::STATUS_FORBIDDEN, ['error' => 'Access to this consultation is not permitted'], $access->error);
	}//end testAnEmptyAssigneeMatchesNobody()

	public function testRequireUserAndCurrentUid(): void {
		$this->assertNull($this->guard('alice')->requireUser());
		$this->assertSame('alice', $this->guard('alice')->currentUid());

		$this->assertResponse(Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated'], $this->guard(null)->requireUser());
		$this->assertSame('', $this->guard(null)->currentUid());
	}//end testRequireUserAndCurrentUid()

	public function testRequireCoordinatorRefusesAnUnauthenticatedCaller(): void {
		$this->assertResponse(Http::STATUS_UNAUTHORIZED, ['error' => 'Not authenticated'], $this->guard(null)->requireCoordinator());
	}//end testRequireCoordinatorRefusesAnUnauthenticatedCaller()

	public function testRequireCoordinatorAdmitsAdminsCoordinatorsAndTheServiceAccount(): void {
		$this->assertNull($this->guard('root', admins: ['root'])->requireCoordinator());
		$this->assertNull($this->guard('cora', groups: ['cora' => ['dossiq-coordinators']])->requireCoordinator());
		$this->assertNull(
			$this->guard('svc', groups: ['svc' => ['dossiq-background-service']], serviceUid: 'svc')->requireCoordinator()
		);
	}//end testRequireCoordinatorAdmitsAdminsCoordinatorsAndTheServiceAccount()

	public function testRequireCoordinatorRefusesOthersIncludingAServiceAccountOutsideItsGroup(): void {
		$refusal = ['error' => 'Only coordinators may read this report'];

		$this->assertResponse(Http::STATUS_FORBIDDEN, $refusal, $this->guard('alice')->requireCoordinator());
		$this->assertResponse(Http::STATUS_FORBIDDEN, $refusal, $this->guard('svc', serviceUid: 'svc')->requireCoordinator());
		$this->assertResponse(
			Http::STATUS_FORBIDDEN,
			$refusal,
			$this->guard('', groups: ['' => ['dossiq-background-service']], serviceUid: '')->requireCoordinator()
		);
	}//end testRequireCoordinatorRefusesOthersIncludingAServiceAccountOutsideItsGroup()

	public function testTheReadableFilterAsksTheCaseGuardOncePerCase(): void {
		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->expects($this->exactly(2))->method('hasCaseReadAccess')->willReturnCallback(
			static fn (string $caseId): bool => $caseId === 'case-mine'
		);

		$filter = $this->guard('alice', caseAccess: $caseAccess)->readableFilter();

		$this->assertTrue($filter(['parentCase' => 'case-mine']));
		$this->assertTrue($filter(['parentCase' => ' case-mine ']));
		$this->assertFalse($filter(['parentCase' => 'case-other']));
		$this->assertFalse($filter(['parentCase' => '']));
		$this->assertFalse($filter([]));
	}//end testTheReadableFilterAsksTheCaseGuardOncePerCase()

	public function testTheReadableFilterIsClosedWithoutACallerAndOpenForTheServiceAccount(): void {
		$this->assertFalse(($this->guard(null)->readableFilter())(['parentCase' => 'case-mine']));

		$caseAccess = $this->createMock(CaseAccessGuard::class);
		$caseAccess->expects($this->never())->method('hasCaseReadAccess');
		$filter = $this->guard('svc', groups: ['svc' => ['dossiq-background-service']], caseAccess: $caseAccess, serviceUid: 'svc')
			->readableFilter();

		$this->assertTrue($filter([]));
	}//end testTheReadableFilterIsClosedWithoutACallerAndOpenForTheServiceAccount()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function noDependencies(): array {
		return [
			'absent' => [[]],
			'empty list' => [['dependsOn' => []]],
			'not a list' => [['dependsOn' => 'cn-2']],
		];
	}//end noDependencies()

	/**
	 * @dataProvider noDependencies
	 *
	 * @param array<string, mixed> $data The create payload.
	 */
	public function testAPayloadWithoutDependenciesIsNotChecked(array $data): void {
		$service = $this->createMock(ConsultationService::class);
		$service->expects($this->never())->method('validateDependencyCycle');

		$this->assertNull($this->guard('alice', service: $service)->dependencyCycleError($data));
	}//end testAPayloadWithoutDependenciesIsNotChecked()

	public function testAnAcyclicDependencyListPasses(): void {
		$service = $this->createMock(ConsultationService::class);
		$service->expects($this->once())->method('validateDependencyCycle')
			->with('', ['cn-2', 'cn-3'])
			->willReturn(false);

		$this->assertNull($this->guard('alice', service: $service)->dependencyCycleError(['dependsOn' => ['cn-2', 'cn-3']]));
	}//end testAnAcyclicDependencyListPasses()

	public function testACyclicDependencyListIsABadRequest(): void {
		$service = $this->createMock(ConsultationService::class);
		$service->method('validateDependencyCycle')->willReturn(true);

		$this->assertResponse(
			Http::STATUS_BAD_REQUEST,
			['error' => 'Dependency cycle detected in dependsOn list'],
			$this->guard('alice', service: $service)->dependencyCycleError(['dependsOn' => ['cn-2']])
		);
	}//end testACyclicDependencyListIsABadRequest()
}//end class
