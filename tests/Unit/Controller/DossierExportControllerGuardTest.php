<?php

/**
 * Unit tests for the per-case guard on the dossier export plan.
 *
 * The plan spans every case linked through `_sourceCase`, so one unauthorised
 * call walks a whole bezwaar and beroep chain rather than a single case. The
 * endpoint is `#[NoAdminRequired]` for the right reason — caseworkers export
 * dossiers, not admins — which puts the entire refusal on `CaseAccessGuard`,
 * and these arms are what prove it is asked before anything is read.
 *
 * The doubles use `onlyMethods`, not `addMethods`: a double that can invent a
 * method the real class lacks passes on a call production would fatal on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DossierExportController;
use OCA\Dossiq\Service\BeroepDossierExport;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that a caller unrelated to the case gets a status and no plan.
 *
 * @covers \OCA\Dossiq\Controller\DossierExportController
 * @uses \OCA\Dossiq\Service\BeroepDossierExport
 * @uses \OCA\Dossiq\Service\CaseAccessGuard
 */
class DossierExportControllerGuardTest extends TestCase {
	/**
	 * @var BeroepDossierExport|MockObject
	 */
	private $fileExport;

	/**
	 * @var CaseAccessGuard|MockObject
	 */
	private $caseAccessGuard;

	/**
	 * @var IUserSession|MockObject
	 */
	private $userSession;

	/**
	 * Set up the collaborators with an authenticated caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->fileExport = $this->getMockBuilder(BeroepDossierExport::class)
			->disableOriginalConstructor()
			->onlyMethods(['buildPlan'])
			->getMock();

		$this->caseAccessGuard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseReadAccess'])
			->getMock();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('outsider');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return DossierExportController
	 */
	private function controller(): DossierExportController {
		return new DossierExportController(
			'dossiq',
			$this->createMock(IRequest::class),
			$this->fileExport,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->caseAccessGuard
		);
	}//end controller()

	/**
	 * A caller with no read access to the case is refused with 403, and the
	 * plan is never built — the refusal precedes every read.
	 *
	 * @return void
	 */
	public function testCallerWithoutCaseAccessIsRefusedBeforeThePlanIsBuilt(): void {
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(false);
		$this->fileExport->expects($this->never())->method('buildPlan');

		$response = $this->controller()->export('someone-elses-case');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}//end testCallerWithoutCaseAccessIsRefusedBeforeThePlanIsBuilt()

	/**
	 * A caller who may read the case still gets the plan. Without this arm a
	 * guard that refused unconditionally would satisfy the arm above.
	 *
	 * @return void
	 */
	public function testCallerWithCaseAccessStillGetsThePlan(): void {
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->fileExport->expects($this->once())
			->method('buildPlan')
			->willReturn(['documents' => []]);

		$response = $this->controller()->export('my-own-case');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['documents' => []], $response->getData());
	}//end testCallerWithCaseAccessStillGetsThePlan()

	/**
	 * A blank case id is a bad request rather than a guard call on nothing.
	 *
	 * @return void
	 */
	public function testABlankCaseIdIsRefusedBeforeTheGuardIsAsked(): void {
		$this->caseAccessGuard->expects($this->never())->method('hasCaseReadAccess');

		$response = $this->controller()->export('   ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testABlankCaseIdIsRefusedBeforeTheGuardIsAsked()
}//end class
