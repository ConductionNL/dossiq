<?php

/**
 * ContactMomentController citizen-lookup authorization tests.
 *
 * Every scenario below is written so the BAD path is the thing under test: an
 * authenticated account that holds no KCC role must not be able to resolve a
 * citizen identifier, and the service that would read the citizen's data must
 * never be reached.
 *
 * Before the guard, `GET /api/kcc/voorblad?burgerId=…` answered HTTP 200 with
 * the citizen's contact history — caller phone number and free-text call
 * summaries included — to any authenticated account. Reproduced live against a
 * running instance with two accounts (finding PROC-IDOR-01).
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
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ContactMomentController;
use OCA\Dossiq\Service\BurgerIdentificationService;
use OCA\Dossiq\Service\CaseVoorbladService;
use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\DoorverbindingService;
use OCA\Dossiq\Service\QuickActionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the citizen-lookup guard on ContactMomentController.
 *
 * @covers \OCA\Dossiq\Controller\ContactMomentController
 */
class ContactMomentControllerAuthorizationTest extends TestCase {

	private ContactMomentService $contactMomentService;

	private CaseVoorbladService $caseVoorbladService;

	private QuickActionService $quickActionService;

	private DoorverbindingService $transferService;

	private BurgerIdentificationService $burgerService;

	private IUserSession $userSession;

	private IRequest $request;

	/**
	 * Set up shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->contactMomentService = $this->createMock(ContactMomentService::class);
		$this->caseVoorbladService = $this->createMock(CaseVoorbladService::class);
		$this->quickActionService = $this->createMock(QuickActionService::class);
		$this->transferService = $this->createMock(DoorverbindingService::class);
		$this->burgerService = $this->createMock(BurgerIdentificationService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build a controller whose citizen-lookup guard answers as given.
	 *
	 * @param bool $allowed Whether the caller holds a KCC role.
	 *
	 * @return ContactMomentController The controller under test.
	 */
	private function controllerWithGuard(bool $allowed): ContactMomentController {
		$guard = $this->createMock(CitizenLookupGuard::class);
		$guard->method('isCitizenLookupAllowed')->willReturn($allowed);

		return new ContactMomentController(
			appName: 'dossiq',
			request: $this->request,
			contactMomentService: $this->contactMomentService,
			caseVoorbladService: $this->caseVoorbladService,
			quickActionService: $this->quickActionService,
			transferService: $this->transferService,
			burgerService: $this->burgerService,
			userSession: $this->userSession,
			citizenLookupGuard: $guard,
		);
	}//end controllerWithGuard()

	/**
	 * An authenticated account without a KCC role cannot read a citizen's
	 * voorblad, and the voorblad service is never called.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testVoorbladIsRefusedWithoutKccRole(): void {
		$this->caseVoorbladService->expects($this->never())->method('getCaseVoorblad');

		$response = $this->controllerWithGuard(allowed: false)->voorblad(burgerId: 'BSN-999999999');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testVoorbladIsRefusedWithoutKccRole()

	/**
	 * A KCC handler still gets the voorblad — the guard denies a role, not the
	 * feature.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testVoorbladIsServedToKccHandler(): void {
		$this->caseVoorbladService->expects($this->once())
			->method('getCaseVoorblad')
			->with('BSN-999999999')
			->willReturn(
				[
					'burgerId' => 'BSN-999999999',
					'openZaken' => [],
					'recenteContactmomenten' => [],
					'suggestedTopic' => '',
				]
			);

		$response = $this->controllerWithGuard(allowed: true)->voorblad(burgerId: 'BSN-999999999');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('BSN-999999999', $response->getData()['burgerId']);
	}//end testVoorbladIsServedToKccHandler()

	/**
	 * The contact-history listing is the same exposure through a second door.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testContactHistoryIsRefusedWithoutKccRole(): void {
		$this->contactMomentService->expects($this->never())->method('listForBurger');

		$response = $this->controllerWithGuard(allowed: false)->index(burgerId: 'BSN-999999999');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testContactHistoryIsRefusedWithoutKccRole()

	/**
	 * A KCC handler still gets the contact history.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testContactHistoryIsServedToKccHandler(): void {
		$this->contactMomentService->expects($this->once())
			->method('listForBurger')
			->with('BSN-999999999', 50)
			->willReturn([]);

		$response = $this->controllerWithGuard(allowed: true)->index(burgerId: 'BSN-999999999');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testContactHistoryIsServedToKccHandler()

	/**
	 * Logging a contactmoment against an arbitrary citizen is refused, and no
	 * write happens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testCreateIsRefusedWithoutKccRole(): void {
		$this->contactMomentService->expects($this->never())->method('createContactMoment');

		$response = $this->controllerWithGuard(allowed: false)->create();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateIsRefusedWithoutKccRole()

	/**
	 * The "nieuwe zaak" quick-action binds a new municipal case to a
	 * caller-supplied citizen id; refused without the role, and nothing is
	 * created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testNieuweZaakIsRefusedWithoutKccRole(): void {
		$this->quickActionService->expects($this->never())->method('executeNieuweZaak');

		$response = $this->controllerWithGuard(allowed: false)->nieuweZaak();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testNieuweZaakIsRefusedWithoutKccRole()

	/**
	 * The "klacht registreren" quick-action takes an arbitrary caseId AND an
	 * arbitrary citizen id; refused without the role.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function testKlachtRegistrerenIsRefusedWithoutKccRole(): void {
		$this->quickActionService->expects($this->never())->method('executeKlachtRegistreren');

		$response = $this->controllerWithGuard(allowed: false)->klachtRegistreren();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testKlachtRegistrerenIsRefusedWithoutKccRole()
}//end class
