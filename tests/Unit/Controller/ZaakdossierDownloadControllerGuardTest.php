<?php

/**
 * Unit tests for the per-case guard on the zaakdossier ZIP download.
 *
 * The endpoint hands out every document of a case as one archive. Its own
 * `#[NoAdminRequired]` posture is correct — caseworkers, not admins, download
 * dossiers — so the refusal has to come from `CaseAccessGuard`, and these two
 * arms are what prove it does.
 *
 * The clearance filter inside `buildZipData()` is deliberately NOT what is
 * under test here: it decides how sensitive a document the caller may see, not
 * whether the caller belongs to this case.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZaakdossierDownloadController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\DossierZipExporter;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that a caller unrelated to the case cannot download its dossier.
 *
 * @covers \OCA\Dossiq\Controller\ZaakdossierDownloadController
 */
class ZaakdossierDownloadControllerGuardTest extends TestCase {
	/**
	 * @var DossierZipExporter|MockObject
	 */
	private $zipExporter;

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
		$this->zipExporter = $this->createMock(DossierZipExporter::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('outsider');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return ZaakdossierDownloadController
	 */
	private function controller(): ZaakdossierDownloadController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return $default;
			}
		);

		return new ZaakdossierDownloadController(
			'dossiq',
			$request,
			$this->createMock(InformatieobjectReader::class),
			$this->zipExporter,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->caseAccessGuard
		);
	}//end controller()

	/**
	 * A caller who is neither assignee nor admin is refused, and no document is
	 * even collected — the refusal precedes every read.
	 *
	 * @return void
	 */
	public function testCallerWithoutCaseAccessIsRefusedBeforeAnyDocumentIsRead(): void {
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(false);
		$this->zipExporter->expects($this->never())->method('collectDocuments');
		$this->zipExporter->expects($this->never())->method('buildZipData');

		$response = $this->controller()->downloadZip('someone-elses-case');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCallerWithoutCaseAccessIsRefusedBeforeAnyDocumentIsRead()

	/**
	 * A caller who works on the case still gets the archive. Without this arm a
	 * guard that refused unconditionally would satisfy the arm above.
	 *
	 * @return void
	 */
	public function testCallerWithCaseAccessStillGetsTheArchive(): void {
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->zipExporter->method('collectDocuments')->willReturn([]);
		$this->zipExporter->expects($this->once())
			->method('buildZipData')
			->willReturn('PK-zip-bytes');

		$response = $this->controller()->downloadZip('my-own-case');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}//end testCallerWithCaseAccessStillGetsTheArchive()
}//end class
