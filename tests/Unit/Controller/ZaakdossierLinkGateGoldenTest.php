<?php

/**
 * Who may link an existing document into a case file, or unlink one from it.
 *
 * Golden over ZaakdossierController::linkExisting() and unlinkDocument()
 * before they share their gate: no session user is 401; no upload access to
 * the case is 403 before the document is read; a document the caller may not
 * read answers the reader's refusal; only then is the link written. A store
 * failure is 503 with its message; a link answers 201 with the link, an
 * unlink 200 with whether a link was removed.
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
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZaakdossierController;
use OCA\Dossiq\Service\Zaakdossier\DocumentApprovalClearance;
use OCA\Dossiq\Service\Zaakdossier\DocumentWriteGate;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The link and unlink endpoints' refusals and answers.
 *
 * @covers \OCA\Dossiq\Controller\ZaakdossierController
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ZaakdossierLinkGateGoldenTest extends TestCase {
	/**
	 * The case-file service double.
	 *
	 * @var ZaakdossierService&MockObject
	 */
	private ZaakdossierService $files;

	/**
	 * The document reader double.
	 *
	 * @var InformatieobjectReader&MockObject
	 */
	private InformatieobjectReader $reader;

	/**
	 * The upload handler double.
	 *
	 * @var DossierUploadHandler&MockObject
	 */
	private DossierUploadHandler $uploads;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = $this->createMock(originalClassName: ZaakdossierService::class);
		$this->reader = $this->createMock(originalClassName: InformatieobjectReader::class);
		$this->uploads = $this->createMock(originalClassName: DossierUploadHandler::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Both endpoints with the store call they reach.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpoints(): array {
		return [
			'link' => ['linkExisting', 'linkExistingInformatieobject'],
			'unlink' => ['unlinkDocument', 'unlinkInformatieobject'],
		];
	}//end endpoints()

	/**
	 * Without a session user the call is 401 and nothing is asked.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $store    The store call it would reach.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutAUserTheCallIsUnauthenticated(string $endpoint, string $store): void {
		$this->session->method('getUser')->willReturn(null);
		$this->uploads->expects(self::never())->method('hasCaseUploadAccess');
		$this->files->expects(self::never())->method($store);

		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutAUserTheCallIsUnauthenticated()

	/**
	 * Without upload access to the case the call is 403, before the document is read.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $store    The store call it would reach.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testWithoutCaseAccessTheCallIsForbidden(string $endpoint, string $store): void {
		$user = $this->signIn();
		$this->uploads->expects(self::once())->method('hasCaseUploadAccess')->with($user, 'c-1')->willReturn(false);
		$this->reader->expects(self::never())->method('guardReadable');
		$this->files->expects(self::never())->method($store);

		self::assertSame(expected: [403, ['error' => 'Not authorized']], actual: $this->answer(endpoint: $endpoint));
	}//end testWithoutCaseAccessTheCallIsForbidden()

	/**
	 * A document the caller may not read answers the reader's refusal as it is.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $store    The store call it would reach.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentIsRefusedByTheReader(string $endpoint, string $store): void {
		$user = $this->signIn();
		$this->uploads->method('hasCaseUploadAccess')->willReturn(true);
		$refusal = new JSONResponse(data: ['error' => 'Not found'], statusCode: 404);
		$this->reader->expects(self::once())->method('guardReadable')->with($user, 'd-1')->willReturn($refusal);
		$this->files->expects(self::never())->method($store);

		self::assertSame(expected: $refusal, actual: $this->call(endpoint: $endpoint));
	}//end testAnUnreadableDocumentIsRefusedByTheReader()

	/**
	 * A store failure is 503 with its message.
	 *
	 * @param string $endpoint The endpoint.
	 * @param string $store    The store call it reaches.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testAStoreFailureIsUnavailable(string $endpoint, string $store): void {
		$this->allow();
		$this->files->expects(self::once())->method($store)->with('c-1', 'd-1')->willThrowException(new RuntimeException('register down'));

		self::assertSame(expected: [503, ['error' => 'register down']], actual: $this->answer(endpoint: $endpoint));
	}//end testAStoreFailureIsUnavailable()

	/**
	 * An allowed link answers 201 with the link.
	 *
	 * @return void
	 */
	public function testAnAllowedLinkIsCreated(): void {
		$this->allow();
		$this->files->expects(self::once())->method('linkExistingInformatieobject')->with('c-1', 'd-1')->willReturn(['id' => 'j-1']);

		self::assertSame(expected: [201, ['id' => 'j-1']], actual: $this->answer(endpoint: 'linkExisting'));
	}//end testAnAllowedLinkIsCreated()

	/**
	 * An allowed unlink answers 200 with whether a link was removed.
	 *
	 * @return void
	 */
	public function testAnAllowedUnlinkSaysWhetherALinkWent(): void {
		$this->allow();
		$this->files->expects(self::once())->method('unlinkInformatieobject')->with('c-1', 'd-1')->willReturn(false);

		self::assertSame(expected: [200, ['unlinked' => false]], actual: $this->answer(endpoint: 'unlinkDocument'));
	}//end testAnAllowedUnlinkSaysWhetherALinkWent()

	/**
	 * A signed-in caller with case access who may read the document.
	 *
	 * @return void
	 */
	private function allow(): void {
		$this->signIn();
		$this->uploads->method('hasCaseUploadAccess')->willReturn(true);
		$this->reader->method('guardReadable')->willReturn(null);
	}//end allow()

	/**
	 * Sign a user in.
	 *
	 * @return IUser The user.
	 */
	private function signIn(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * The status and data of one endpoint's answer.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return array{0: int, 1: mixed} Status and data.
	 */
	private function answer(string $endpoint): array {
		$response = $this->call(endpoint: $endpoint);

		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * Call one endpoint for case c-1 and document d-1.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @return JSONResponse The answer.
	 */
	private function call(string $endpoint): JSONResponse {
		$controller = new ZaakdossierController(
			'dossiq',
			$this->createMock(originalClassName: IRequest::class),
			$this->files,
			$this->reader,
			$this->uploads,
			$this->session,
			$this->createMock(originalClassName: DocumentApprovalClearance::class),
			$this->createMock(originalClassName: DocumentWriteGate::class),
		);

		return $controller->$endpoint(caseId: 'c-1', infoObjectId: 'd-1');
	}//end call()
}//end class
