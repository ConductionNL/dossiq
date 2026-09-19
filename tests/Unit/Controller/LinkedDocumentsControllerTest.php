<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The linked rows answer only for a signed-in user, and only the documents
 * that user may read.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\LinkedDocumentsController;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\Zaakdossier\LinkedDocumentsReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LinkedDocumentsControllerTest extends TestCase {
	/** @var LinkedDocumentsReader&MockObject The rows double. */
	private LinkedDocumentsReader&MockObject $rows;

	/** @var InformatieobjectReader&MockObject The clearance double. */
	private InformatieobjectReader&MockObject $reader;

	/** @var IUserSession&MockObject The session double. */
	private IUserSession&MockObject $session;

	/** @var LinkedDocumentsController The controller under test. */
	private LinkedDocumentsController $controller;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = $this->createMock(originalClassName: LinkedDocumentsReader::class);
		$this->reader = $this->createMock(originalClassName: InformatieobjectReader::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->controller = new LinkedDocumentsController(
			appName: 'dossiq',
			request: $this->createMock(originalClassName: IRequest::class),
			linkedDocuments: $this->rows,
			reader: $this->reader,
			userSession: $this->session,
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testAnAnonymousCallerGetsNothing(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->rows->expects($this->never())->method('linkedDocuments');

		$response = $this->controller->index(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnAnonymousCallerGetsNothing()

	/**
	 * @return void
	 */
	public function testOnlyTheReadableRowsAreAnswered(): void {
		$this->session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$this->rows->method('linkedDocuments')->with('case-1')->willReturn([
			['id' => 'rec-open', 'name' => 'a.pdf'],
			['id' => 'rec-secret', 'name' => 'b.pdf'],
		]);
		$this->reader->method('guardReadable')->willReturnCallback(
			static function (IUser $user, string $infoObjectId): ?JSONResponse {
				if ($infoObjectId === 'rec-secret') {
					return new JSONResponse(['error' => 'clearance'], Http::STATUS_FORBIDDEN);
				}

				return null;
			}
		);

		$response = $this->controller->index(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['rec-open'], actual: array_column($response->getData(), 'id'));
	}//end testOnlyTheReadableRowsAreAnswered()

	/**
	 * @return void
	 */
	public function testARegisterThatIsDownIs503NotA500(): void {
		$this->session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$this->rows->method('linkedDocuments')->willThrowException(new RuntimeException('OpenRegister is not available'));

		$response = $this->controller->index(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testARegisterThatIsDownIs503NotA500()
}//end class
