<?php

/**
 * ZaakdossierController::listDossier() Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the one dossier endpoint left without
 * executed proof of its wire behaviour: `listDossier()`, the case dossier
 * listing. It is `@NoAdminRequired` and returns document metadata whose
 * visibility depends on the caller's clearance
 * (`vertrouwelijkheidaanduiding`), enforced server-side by
 * `InformatieobjectReader::filterForUser()` (ADR-005 Rule 3). These tests pin:
 *
 *  - an anonymous caller is refused 401 and the dossier is never fetched;
 *  - an unavailable dossier store is a 503 carrying the store's own message,
 *    not an empty 200 a client would render as "this case has no documents";
 *  - the clearance filter is applied for the SESSION user, and — the assertion
 *    that matters — the list that gets GROUPED and returned is the FILTERED
 *    one. Grouping the raw list instead would put every confidential document
 *    back on the wire while the filter still ran and still reported success:
 *    the filter would be executed, correct, and bypassed. Only comparing what
 *    goes INTO `groupByType()` against what came OUT of the filter can see that;
 *  - a dossier with no `informatieobjecten` key still reaches the filter with an
 *    empty list rather than throwing on the missing key.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ZaakdossierController;
use OCA\Procest\Service\Zaakdossier\DossierUploadHandler;
use OCA\Procest\Service\Zaakdossier\InformatieobjectReader;
use OCA\Procest\Service\ZaakdossierService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for ZaakdossierController::listDossier().
 *
 * @covers \OCA\Procest\Controller\ZaakdossierController
 */
class ZaakdossierListContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The dossier orchestrator mock.
	 *
	 * @var ZaakdossierService|MockObject
	 */
	private ZaakdossierService $fileService;

	/**
	 * The clearance-gated document reader mock.
	 *
	 * @var InformatieobjectReader|MockObject
	 */
	private InformatieobjectReader $reader;

	/**
	 * The upload handler mock (unused by `listDossier()`, required to construct).
	 *
	 * @var DossierUploadHandler|MockObject
	 */
	private DossierUploadHandler $uploadHandler;

	/**
	 * The user session mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ZaakdossierController
	 */
	private ZaakdossierController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->fileService = $this->createMock(ZaakdossierService::class);
		$this->reader = $this->createMock(InformatieobjectReader::class);
		$this->uploadHandler = $this->createMock(DossierUploadHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ZaakdossierController(
			appName: 'procest',
			request: $this->request,
			fileService: $this->fileService,
			reader: $this->reader,
			uploadHandler: $this->uploadHandler,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Put an authenticated user in the session.
	 *
	 * @param string $uid The user id the session reports.
	 *
	 * @return IUser|MockObject The signed-in user.
	 */
	private function signIn(string $uid = 'handler-1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * An anonymous caller is refused 401 and the dossier is never fetched.
	 *
	 * @return void
	 */
	public function testListDossierRefusesAnAnonymousCallerWithoutFetchingTheDossier(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->fileService->expects($this->never())->method('getDossierForCase');
		$this->reader->expects($this->never())->method('filterForUser');

		$response = $this->controller->listDossier(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testListDossierRefusesAnAnonymousCallerWithoutFetchingTheDossier()

	/**
	 * An unavailable dossier store is a 503 carrying the store's own message —
	 * never an empty 200 that a client renders as "no documents on this case".
	 *
	 * @return void
	 */
	public function testListDossierAnswers503WhenTheDossierStoreIsUnavailable(): void {
		$this->signIn();
		$this->fileService->method('getDossierForCase')
			->willThrowException(new \RuntimeException('OpenRegister register not configured'));
		$this->reader->expects($this->never())->method('filterForUser');
		$this->fileService->expects($this->never())->method('groupByType');

		$response = $this->controller->listDossier(caseId: 'case-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'OpenRegister register not configured'], $response->getData());
	}//end testListDossierAnswers503WhenTheDossierStoreIsUnavailable()

	/**
	 * The clearance filter runs for the session user, and the list that is
	 * grouped and returned is the FILTERED one — a confidential document the
	 * reader removed must not reappear in the response because the grouping was
	 * fed the raw dossier.
	 *
	 * @return void
	 */
	public function testListDossierGroupsTheClearanceFilteredListAndNotTheRawDossier(): void {
		$this->signIn(uid: 'handler-1');

		$public = ['id' => 'doc-1', 'vertrouwelijkheidaanduiding' => 'openbaar'];
		$secret = ['id' => 'doc-2', 'vertrouwelijkheidaanduiding' => 'zeer_geheim'];
		$this->fileService->method('getDossierForCase')->willReturn(
			['informatieobjecten' => [$public, $secret]]
		);

		$filterSeen = [];
		$this->reader->expects($this->once())
			->method('filterForUser')
			->willReturnCallback(
				static function (IUser $user, array $informatieobjecten) use (&$filterSeen, $public): array {
					$filterSeen = ['uid' => $user->getUID(), 'offered' => $informatieobjecten];
					return [$public];
				}
			);

		$grouped = [];
		$this->fileService->expects($this->once())
			->method('groupByType')
			->willReturnCallback(
				static function (array $documents) use (&$grouped): array {
					$grouped = $documents;
					return ['overig' => $documents];
				}
			);

		$response = $this->controller->listDossier(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['uid' => 'handler-1', 'offered' => [$public, $secret]],
			$filterSeen,
			'the whole dossier must be offered to the clearance filter for the session user'
		);
		$this->assertSame(
			[$public],
			$grouped,
			'grouping must consume the FILTERED list — the removed document must not be regrouped'
		);
		$this->assertSame(['overig' => [$public]], $response->getData());
	}//end testListDossierGroupsTheClearanceFilteredListAndNotTheRawDossier()

	/**
	 * A dossier without an `informatieobjecten` key still reaches the filter,
	 * with an empty list — the endpoint must not throw on a case that has no
	 * documents yet.
	 *
	 * @return void
	 */
	public function testListDossierOffersAnEmptyListWhenTheDossierHasNoDocuments(): void {
		$this->signIn();
		$this->fileService->method('getDossierForCase')->willReturn(['zaak' => 'case-1']);

		$offered = null;
		$this->reader->expects($this->once())
			->method('filterForUser')
			->willReturnCallback(
				static function (IUser $user, array $informatieobjecten) use (&$offered): array {
					$offered = $informatieobjecten;
					return [];
				}
			);
		$this->fileService->method('groupByType')->willReturn([]);

		$response = $this->controller->listDossier(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $offered);
		$this->assertSame([], $response->getData());
	}//end testListDossierOffersAnEmptyListWhenTheDossierHasNoDocuments()
}//end class
