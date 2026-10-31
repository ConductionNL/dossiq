<?php

/**
 * DocumentCopyController unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentCopyController;
use OCA\Dossiq\Service\Zaakdossier\DocumentCopier;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Copying documents to another case.
 *
 * @covers \OCA\Dossiq\Controller\DocumentCopyController
 *
 * @spec openspec/specs/documents-copy-to-case/spec.md#requirement-documents-are-copied-to-a-case-the-caller-may-add-to
 */
final class DocumentCopyControllerTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The right to add documents to a case.
	 *
	 * @var DossierUploadHandler|MockObject
	 */
	private DossierUploadHandler $uploadHandler;

	/**
	 * Build the request and the upload handler; the caller is signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnMap(valueMap: [['ids', [], ['doc-1']], ['targetCaseId', '', 'case-2']]);
		$this->uploadHandler = $this->createMock(DossierUploadHandler::class);
	}//end setUp()

	/**
	 * The controller with the given copier and a signed-in caller.
	 *
	 * @param DocumentCopier $copier The copier.
	 *
	 * @return DocumentCopyController
	 */
	private function controller(DocumentCopier $copier): DocumentCopyController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(value: $this->createMock(IUser::class));

		return new DocumentCopyController(
			appName: 'dossiq',
			request: $this->request,
			userSession: $session,
			uploadHandler: $this->uploadHandler,
			copier: $copier,
		);
	}//end controller()

	/**
	 * Copying to a case the caller may not add to is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testBulkCopyRefusesATargetCaseTheCallerMayNotAddTo(): void {
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(value: false);
		$copier = $this->createMock(originalClassName: DocumentCopier::class);
		$copier->expects($this->never())->method('copyAll');

		$response = $this->controller(copier: $copier)->bulkCopy();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testBulkCopyRefusesATargetCaseTheCallerMayNotAddTo()

	/**
	 * A permitted copy answers the copier's per-document results.
	 *
	 * @return void
	 */
	public function testBulkCopyAnswersThePerDocumentResults(): void {
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(value: true);
		$copier = $this->createMock(originalClassName: DocumentCopier::class);
		$copier->expects($this->once())->method('copyAll')->willReturn(value: [['id' => 'doc-1', 'success' => true, 'copyId' => 'c-1']]);

		$response = $this->controller(copier: $copier)->bulkCopy();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['results' => [['id' => 'doc-1', 'success' => true, 'copyId' => 'c-1']]], actual: $response->getData());
	}//end testBulkCopyAnswersThePerDocumentResults()
}//end class
