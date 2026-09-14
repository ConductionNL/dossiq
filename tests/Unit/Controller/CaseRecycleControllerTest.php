<?php

/**
 * CaseRecycleController wire contract.
 *
 * Five `#[NoAdminRequired]` endpoints that take a case uuid from the URL, one
 * of which destroys the case for good. So the assertions are about the door
 * rather than about the work behind it: an anonymous caller never reaches a
 * service, a refusal answers its own code, and an unexpected failure says
 * nothing about itself.
 *
 * The destroying role lives in {@see \OCA\Dossiq\Service\Recycle\CaseDestructionService}
 * and is asserted in `tests/Unit/Service/CaseDestructionTest.php`; here the
 * question is only whether the refusal reaches the caller intact.
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

use OCA\Dossiq\Controller\CaseRecycleController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Recycle\CaseDestructionService;
use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\Recycle\RetentionClocks;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The lens, the restore, the preview, the destroy and the clocks.
 *
 * @covers \OCA\Dossiq\Controller\CaseRecycleController
 */
class CaseRecycleControllerTest extends TestCase {

	/**
	 * The lens and the restore.
	 *
	 * @var CaseRecycleService&MockObject
	 */
	private CaseRecycleService $recycle;

	/**
	 * The destroying role and the act.
	 *
	 * @var CaseDestructionService&MockObject
	 */
	private CaseDestructionService $destruction;

	/**
	 * The two clocks.
	 *
	 * @var RetentionClocks&MockObject
	 */
	private RetentionClocks $clocks;

	/**
	 * The per-case guard, consulted on the clocks read.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The request, carrying the waive flag.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Wire a controller with a signed-in user by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->recycle = $this->createMock(originalClassName: CaseRecycleService::class);
		$this->destruction = $this->createMock(originalClassName: CaseDestructionService::class);
		$this->clocks = $this->createMock(originalClassName: RetentionClocks::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseRecycleController
	 */
	private function controller(): CaseRecycleController {
		return new CaseRecycleController(
			'dossiq',
			$this->request,
			$this->recycle,
			$this->destruction,
			$this->clocks,
			$this->guard,
			$this->userSession,
			$this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * REQ-CRW-01: the lens answers the deleted cases with the date each window
	 * ends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheLensAnswersTheDeletedCases(): void {
		$this->recycle->method('deletedCases')->willReturn(
			[
				'results' => [['id' => 'case-9', 'windowEndsOn' => '2034-01-31']],
				'total' => 1,
			]
		);

		$response = $this->controller()->deleted();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('2034-01-31', $response->getData()['results'][0]['windowEndsOn']);
	}//end testTheLensAnswersTheDeletedCases()

	/**
	 * REQ-CRW-02: restoring answers the window the case came back inside.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testRestoreAnswersTheWindowItWasInside(): void {
		$this->recycle->method('restore')->willReturn(
			['success' => true, 'caseId' => 'case-9', 'restoredWithin' => ['daysRemaining' => 12]]
		);

		$response = $this->controller()->restore(caseId: 'case-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(12, $response->getData()['restoredWithin']['daysRemaining']);
	}//end testRestoreAnswersTheWindowItWasInside()

	/**
	 * REQ-CRW-02: a refused destruction answers the rule that refused it, so
	 * the page can say why rather than printing a bare Forbidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testARefusedDestructionAnswersItsRule(): void {
		$this->destruction->method('destroy')
			->willThrowException(new RuntimeException('destroy_role_missing'));

		$response = $this->controller()->destroy(caseId: 'case-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('destroy_role_missing', $response->getData()['code']);
	}//end testARefusedDestructionAnswersItsRule()

	/**
	 * An open recovery window and disagreeing clocks are conflicts rather than
	 * refusals: the caller may act again once the condition changes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnOpenWindowAndDisagreeingClocksAnswer409(): void {
		$statuses = [];
		foreach (['recovery_window_open', 'retention_clocks_disagree'] as $code) {
			$destruction = $this->createMock(originalClassName: CaseDestructionService::class);
			$destruction->method('destroy')->willThrowException(new RuntimeException($code));
			$this->destruction = $destruction;

			$statuses[$code] = $this->controller()->destroy(caseId: 'case-9')->getStatus();
		}

		$this->assertSame(
			['recovery_window_open' => Http::STATUS_CONFLICT, 'retention_clocks_disagree' => Http::STATUS_CONFLICT],
			$statuses
		);
	}//end testAnOpenWindowAndDisagreeingClocksAnswer409()

	/**
	 * The preview answers the scope, the window and both clocks, and asks the
	 * destruction service for a preview rather than for the act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testThePreviewNeverDestroys(): void {
		$this->destruction->method('preview')->willReturn(
			['caseId' => 'case-9', 'scope' => ['scope' => []], 'clocks' => ['disagree' => false]]
		);
		$this->destruction->expects($this->never())->method('destroy');

		$response = $this->controller()->destructionPreview(caseId: 'case-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('case-9', $response->getData()['caseId']);
	}//end testThePreviewNeverDestroys()

	/**
	 * REQ-CRW-03: the clocks read is guarded per case, and it reads the LIVE
	 * case rather than the trash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheClocksReadIsGuarded(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->recycle->expects($this->never())->method('liveCase');
		$this->clocks->expects($this->never())->method('clocksFor');

		$response = $this->controller()->clocks(caseId: 'case-9');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testTheClocksReadIsGuarded()

	/**
	 * REQ-CRW-03: a permitted reader gets both clocks, labelled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testTheClocksReadAnswersBothDates(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->recycle->method('liveCase')->willReturn(['identifier' => 'ZAAK-2026-0009']);
		$this->clocks->method('clocksFor')->willReturn(
			[
				'lawfulPurpose' => ['date' => '2024-01-31', 'label' => 'Lawful purpose ends'],
				'archive' => ['date' => '2034-01-31', 'label' => 'Archive action due'],
				'disagree' => true,
			]
		);

		$data = $this->controller()->clocks(caseId: 'case-9')->getData();

		$this->assertSame('2024-01-31', $data['lawfulPurpose']['date']);
		$this->assertSame('2034-01-31', $data['archive']['date']);
		$this->assertTrue($data['disagree']);
	}//end testTheClocksReadAnswersBothDates()

	/**
	 * An anonymous caller reaches no service on any of the five endpoints.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnAnonymousCallerReachesNothing(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->recycle->expects($this->never())->method('deletedCases');
		$this->recycle->expects($this->never())->method('restore');
		$this->destruction->expects($this->never())->method('preview');
		$this->destruction->expects($this->never())->method('destroy');
		$this->clocks->expects($this->never())->method('clocksFor');

		$controller = $this->controller();
		$statuses = [
			$controller->deleted()->getStatus(),
			$controller->restore(caseId: 'case-9')->getStatus(),
			$controller->destructionPreview(caseId: 'case-9')->getStatus(),
			$controller->destroy(caseId: 'case-9')->getStatus(),
			$controller->clocks(caseId: 'case-9')->getStatus(),
		];

		$this->assertSame(array_fill(0, 5, Http::STATUS_UNAUTHORIZED), $statuses);
	}//end testAnAnonymousCallerReachesNothing()

	/**
	 * An unexpected failure is a 500 that withholds its detail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
	 */
	public function testAnUnexpectedFailureWithholdsItsDetail(): void {
		$this->recycle->method('restore')
			->willThrowException(new \LogicException('connection string leaked here'));

		$response = $this->controller()->restore(caseId: 'case-9');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('connection', json_encode($response->getData()));
	}//end testAnUnexpectedFailureWithholdsItsDetail()
}//end class
