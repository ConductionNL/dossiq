<?php

/**
 * Unit tests for ConsultationController's show, create, updateStatus and
 * submitResponse actions, behind a REAL ConsultationAccessGuard.
 *
 * @category Test
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

use OCA\Dossiq\Controller\ConsultationController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Consultation\ConsultationAccess;
use OCA\Dossiq\Service\Consultation\ConsultationAccessGuard;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may read and change a consultation, and how refusals and failures answer.
 */
#[CoversClass(ConsultationController::class)]
#[UsesClass(ConsultationAccessGuard::class)]
#[UsesClass(ConsultationAccess::class)]
final class ConsultationControllerActionsTest extends TestCase {

	/**
	 * The consultation service.
	 *
	 * @var ConsultationService&MockObject
	 */
	private ConsultationService&MockObject $consultationService;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The raw request body the request hands out.
	 *
	 * @var string
	 */
	private string $body = '';

	/**
	 * The consultation every test reads.
	 *
	 * @var array<string,mixed>
	 */
	private array $consultation = ['id' => 'c-1', 'applicant' => 'anna', 'assignee' => 'bert', 'status' => 'open'];

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->consultationService = $this->createMock(ConsultationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->consultationService->method('getConsultation')->willReturnCallback(
			fn (string $consultationId): ?array => ($consultationId === 'c-1' ? $this->consultation : null)
		);
	}//end setUp()

	/**
	 * The controller behind a real guard, over a request whose body is $this->body.
	 *
	 * @return ConsultationController
	 */
	private function controller(): ConsultationController {
		$request = $this->getMockBuilder(IRequest::class)->addMethods(['getContent'])->getMockForAbstractClass();
		$request->method('getContent')->willReturnCallback(fn (): string => $this->body);

		return new ConsultationController(
			appName: 'dossiq',
			request: $request,
			consultationService: $this->consultationService,
			accessGuard: new ConsultationAccessGuard(
				request: $request,
				consultationService: $this->consultationService,
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				caseAccess: $this->createMock(CaseAccessGuard::class),
				serviceAccount: $this->createMock(BackgroundServiceAccount::class),
			),
		);
	}//end controller()

	/**
	 * Sign a user in, or nobody.
	 *
	 * @param string|null $uid The user, or null.
	 *
	 * @return void
	 */
	private function signIn(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * show answers 401 without a user, 404 for an unknown id, 403 for a stranger
	 * and the consultation for its assignee.
	 *
	 * @return void
	 */
	public function testShowAnswersOnlyThoseWithAClaim(): void {
		$this->signIn(null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->show(id: 'c-1')->getStatus());

		$this->setUp();
		$this->signIn('carla');
		$controller = $this->controller();
		$missing = $controller->show(id: 'c-gone');
		self::assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		self::assertSame(['error' => 'Consultation not found'], $missing->getData());
		$stranger = $controller->show(id: 'c-1');
		self::assertSame(Http::STATUS_FORBIDDEN, $stranger->getStatus());
		self::assertSame(['error' => 'Access to this consultation is not permitted'], $stranger->getData());

		$this->setUp();
		$this->signIn('bert');
		$shown = $this->controller()->show(id: 'c-1');
		self::assertSame(Http::STATUS_OK, $shown->getStatus());
		self::assertSame($this->consultation, $shown->getData());
	}//end testShowAnswersOnlyThoseWithAClaim()

	/**
	 * create records the caller as applicant whatever the body says, and answers 201.
	 *
	 * @return void
	 */
	public function testCreateMakesTheCallerTheApplicant(): void {
		$this->signIn('anna');
		$this->body = (string)json_encode(['subject' => 'Advies brandweer', 'applicant' => 'mallory']);
		$this->consultationService->expects(self::once())->method('createConsultation')
			->with(data: ['subject' => 'Advies brandweer', 'applicant' => 'anna'])
			->willReturn(['id' => 'c-9']);

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 'c-9'], $response->getData());
	}//end testCreateMakesTheCallerTheApplicant()

	/**
	 * A dependency cycle is refused before anything is created.
	 *
	 * @return void
	 */
	public function testCreateRefusesADependencyCycle(): void {
		$this->signIn('anna');
		$this->body = (string)json_encode(['dependsOn' => ['c-2', 'c-3']]);
		$this->consultationService->expects(self::once())->method('validateDependencyCycle')
			->with(consultationId: '', dependsOn: ['c-2', 'c-3'])->willReturn(true);
		$this->consultationService->expects(self::never())->method('createConsultation');

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Dependency cycle detected in dependsOn list'], $response->getData());
	}//end testCreateRefusesADependencyCycle()

	/**
	 * A refusal from the service on create is a 400 carrying its message.
	 *
	 * @return void
	 */
	public function testCreateReportsARefusalAs400(): void {
		$this->signIn('anna');
		$this->body = (string)json_encode(['dependsOn' => ['c-2']]);
		$this->consultationService->method('validateDependencyCycle')->willReturn(false);
		$this->consultationService->method('createConsultation')->willThrowException(new RuntimeException('Subject is required'));

		$response = $this->controller()->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Subject is required'], $response->getData());
	}//end testCreateReportsARefusalAs400()

	/**
	 * updateStatus passes the requested status on; a missing one is passed as empty.
	 *
	 * @return void
	 */
	public function testUpdateStatusPassesTheRequestedStatus(): void {
		$this->signIn('anna');
		$this->body = (string)json_encode(['status' => 'in_handling']);
		$this->consultationService->expects(self::exactly(2))->method('updateStatus')
			->willReturnCallback(
				static fn (string $consultationId, string $newStatus): array => ['id' => $consultationId, 'status' => $newStatus]
			);
		$controller = $this->controller();

		self::assertSame(['id' => 'c-1', 'status' => 'in_handling'], $controller->updateStatus(id: 'c-1')->getData());

		$this->body = '{}';
		self::assertSame(['id' => 'c-1', 'status' => ''], $controller->updateStatus(id: 'c-1')->getData());
	}//end testUpdateStatusPassesTheRequestedStatus()

	/**
	 * updateStatus refuses a stranger before reading the body, and reports a refused transition as 400.
	 *
	 * @return void
	 */
	public function testUpdateStatusRefusals(): void {
		$this->signIn('carla');
		$this->consultationService->expects(self::never())->method('updateStatus');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->updateStatus(id: 'c-1')->getStatus());

		$this->setUp();
		$this->signIn('admin');
		$this->body = (string)json_encode(['status' => 'closed']);
		$this->consultationService->method('updateStatus')->willThrowException(new RuntimeException('Invalid transition'));
		$response = $this->controller()->updateStatus(id: 'c-1');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Invalid transition'], $response->getData());
	}//end testUpdateStatusRefusals()

	/**
	 * submitResponse hands the whole body on as the response, and reports a refusal as 400.
	 *
	 * @return void
	 */
	public function testSubmitResponse(): void {
		$this->signIn('bert');
		$this->body = (string)json_encode(['advice' => 'positief', 'remarks' => 'Geen bezwaar']);
		$this->consultationService->expects(self::once())->method('submitResponse')
			->with(consultationId: 'c-1', response: ['advice' => 'positief', 'remarks' => 'Geen bezwaar'])
			->willReturn(['id' => 'c-1', 'status' => 'answered']);

		$response = $this->controller()->submitResponse(id: 'c-1');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'c-1', 'status' => 'answered'], $response->getData());

		$this->setUp();
		$this->signIn('bert');
		$this->body = 'not json';
		$this->consultationService->method('submitResponse')->willThrowException(new RuntimeException('Already answered'));
		$refused = $this->controller()->submitResponse(id: 'c-1');
		self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		self::assertSame(['error' => 'Already answered'], $refused->getData());

		$this->setUp();
		$this->signIn(null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->submitResponse(id: 'c-1')->getStatus());
	}//end testSubmitResponse()
}//end class
