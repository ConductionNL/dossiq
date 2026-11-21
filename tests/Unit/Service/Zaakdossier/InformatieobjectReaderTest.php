<?php

/**
 * Informatieobject Reader Test
 *
 * Loading a document behind the clearance guard answers the right error
 * response for each failure, and the reader's pass-throughs reach their
 * collaborator.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\Zaakdossier\AccessExceptionNames;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader
 */
class InformatieobjectReaderTest extends TestCase {

	private ZaakdossierService&MockObject $fileService;

	private InformatieobjectAccessGuard&MockObject $guard;

	private ZgwDocumentService&MockObject $documents;

	private IUser&MockObject $user;

	protected function setUp(): void {
		$this->fileService = $this->createMock(ZaakdossierService::class);
		$this->guard = $this->createMock(InformatieobjectAccessGuard::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @param AccessExceptionNames|null $names The exception names service.
	 *
	 * @return InformatieobjectReader
	 */
	private function reader(?AccessExceptionNames $names = null): InformatieobjectReader {
		return new InformatieobjectReader(
			fileService: $this->fileService,
			accessGuard: $this->guard,
			documentService: $this->documents,
			exceptionNames: $names,
		);
	}//end reader()

	public function testAReadableDocumentIsReturned(): void {
		$doc = ['id' => 'doc-1', 'vertrouwelijkheidaanduiding' => 'openbaar'];
		$this->fileService->expects(self::once())->method('getInformatieobject')->with('doc-1')->willReturn($doc);
		$this->guard->expects(self::once())->method('assertCanRead')->with($this->user, $doc);

		self::assertSame($doc, $this->reader()->loadReadable($this->user, 'doc-1'));
	}//end testAReadableDocumentIsReturned()

	public function testTheGuardPassesAReadableDocument(): void {
		$this->fileService->method('getInformatieobject')->willReturn(['id' => 'doc-1']);

		self::assertNull($this->reader()->guardReadable($this->user, 'doc-1'));
	}//end testTheGuardPassesAReadableDocument()

	public function testAnUnavailableRegisterAnswers503WithItsMessage(): void {
		$this->fileService->method('getInformatieobject')->willThrowException(new RuntimeException('Register not configured'));
		$this->guard->expects(self::never())->method('assertCanRead');

		$response = $this->reader()->loadReadable($this->user, 'doc-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame(['error' => 'Register not configured'], $response->getData());
	}//end testAnUnavailableRegisterAnswers503WithItsMessage()

	public function testAMissingDocumentAnswers404(): void {
		$this->fileService->method('getInformatieobject')->willReturn(null);
		$this->guard->expects(self::never())->method('assertCanRead');

		$response = $this->reader()->guardReadable($this->user, 'gone');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Informatieobject not found'], $response->getData());
	}//end testAMissingDocumentAnswers404()

	public function testInsufficientClearanceAnswers403(): void {
		$this->fileService->method('getInformatieobject')->willReturn(['id' => 'secret']);
		$this->guard->method('assertCanRead')->willThrowException(new NotPermittedException('no'));

		$response = $this->reader()->guardReadable($this->user, 'secret');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'Insufficient clearance for this document'], $response->getData());
	}//end testInsufficientClearanceAnswers403()

	public function testFilterForUserAnswersWhatTheGuardKeeps(): void {
		$all = [['id' => 'a'], ['id' => 'b']];
		$this->guard->expects(self::once())->method('filterDossierForUser')->with($this->user, $all)->willReturn([['id' => 'a']]);

		self::assertSame([['id' => 'a']], $this->reader()->filterForUser($this->user, $all));
	}//end testFilterForUserAnswersWhatTheGuardKeeps()

	public function testContentIsReadFromTheStoredFile(): void {
		$this->documents->expects(self::once())->method('getContent')->with('doc-1', 'brief.pdf')->willReturn('%PDF');

		self::assertSame('%PDF', $this->reader()->contentFor('doc-1', 'brief.pdf'));
	}//end testContentIsReadFromTheStoredFile()

	public function testAMissingFileAnswersNullContent(): void {
		$this->documents->method('getContent')->willThrowException(new NotFoundException('gone'));

		self::assertNull($this->reader()->contentFor('doc-1', 'brief.pdf'));
	}//end testAMissingFileAnswersNullContent()

	public function testWithoutTheNamesServiceRowsAreUnchanged(): void {
		self::assertSame([['id' => 'a']], $this->reader()->withAccessExceptionNames([['id' => 'a']]));
	}//end testWithoutTheNamesServiceRowsAreUnchanged()

	public function testExceptionNamesComeFromTheNamesService(): void {
		$names = $this->createMock(AccessExceptionNames::class);
		$names->expects(self::once())->method('withNames')->with([['id' => 'a']])->willReturn([['id' => 'a', 'accessExceptionNames' => ['Alice']]]);

		self::assertSame([['id' => 'a', 'accessExceptionNames' => ['Alice']]], $this->reader($names)->withAccessExceptionNames([['id' => 'a']]));
	}//end testExceptionNamesComeFromTheNamesService()
}//end class
