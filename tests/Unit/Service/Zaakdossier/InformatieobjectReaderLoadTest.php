<?php

/**
 * InformatieobjectReader load and guard unit tests.
 *
 * A document is answered only when it exists and the caller has the clearance
 * for it; every refusal is a JSON error with its own status, never the document.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
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
use OCP\Files\NotPermittedException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Loading, guarding, filtering and reading the bytes of a document.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T05
 */
class InformatieobjectReaderLoadTest extends TestCase {

	private ZaakdossierService&MockObject $files;

	private InformatieobjectAccessGuard&MockObject $guard;

	private ZgwDocumentService&MockObject $documents;

	private IUser&MockObject $user;

	protected function setUp(): void {
		$this->files     = $this->createMock(ZaakdossierService::class);
		$this->guard     = $this->createMock(InformatieobjectAccessGuard::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$this->user      = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @param AccessExceptionNames|null $names The exception names, when wired.
	 *
	 * @return InformatieobjectReader
	 */
	private function reader(?AccessExceptionNames $names = null): InformatieobjectReader {
		return new InformatieobjectReader(
			fileService: $this->files,
			accessGuard: $this->guard,
			documentService: $this->documents,
			exceptionNames: $names,
		);
	}//end reader()

	/**
	 * A readable document is answered as loaded, after the clearance check ran on it.
	 *
	 * @return void
	 */
	public function testAReadableDocumentIsAnswered(): void {
		$doc = ['id' => 'doc-1', 'vertrouwelijkheidaanduiding' => 'openbaar'];
		$this->files->expects($this->exactly(2))->method('getInformatieobject')->with('doc-1')->willReturn($doc);
		$this->guard->expects($this->exactly(2))->method('assertCanRead')->with($this->user, $doc);

		self::assertSame($doc, $this->reader()->loadReadable(user: $this->user, infoObjectId: 'doc-1'));
		self::assertNull($this->reader()->guardReadable(user: $this->user, infoObjectId: 'doc-1'));
	}//end testAReadableDocumentIsAnswered()

	/**
	 * A store that cannot answer is 503 with its message; the guard is not asked.
	 *
	 * @return void
	 */
	public function testAStoreThatCannotAnswerIs503(): void {
		$this->files->method('getInformatieobject')->willThrowException(new RuntimeException('OpenRegister is not available'));
		$this->guard->expects($this->never())->method('assertCanRead');

		$response = $this->reader()->loadReadable(user: $this->user, infoObjectId: 'doc-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame(['error' => 'OpenRegister is not available'], $response->getData());
	}//end testAStoreThatCannotAnswerIs503()

	/**
	 * A document that does not exist is 404, and guardReadable hands that response on.
	 *
	 * @return void
	 */
	public function testAMissingDocumentIs404(): void {
		$this->files->method('getInformatieobject')->willReturn(null);
		$this->guard->expects($this->never())->method('assertCanRead');

		$response = $this->reader()->guardReadable(user: $this->user, infoObjectId: 'gone');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Informatieobject not found'], $response->getData());
	}//end testAMissingDocumentIs404()

	/**
	 * A caller without the clearance gets 403 and never the document.
	 *
	 * @return void
	 */
	public function testInsufficientClearanceIs403(): void {
		$this->files->method('getInformatieobject')->willReturn(['id' => 'doc-1', 'vertrouwelijkheidaanduiding' => 'geheim']);
		$this->guard->method('assertCanRead')->willThrowException(new NotPermittedException('no'));

		$response = $this->reader()->loadReadable(user: $this->user, infoObjectId: 'doc-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'Insufficient clearance for this document'], $response->getData());
	}//end testInsufficientClearanceIs403()

	/**
	 * Filtering a dossier is the guard's answer for this user.
	 *
	 * @return void
	 */
	public function testFilteringIsTheGuardsAnswer(): void {
		$all = [['id' => 'a'], ['id' => 'b']];
		$this->guard->expects($this->once())->method('filterDossierForUser')->with($this->user, $all)->willReturn([['id' => 'a']]);

		self::assertSame([['id' => 'a']], $this->reader()->filterForUser(user: $this->user, informatieobjecten: $all));
	}//end testFilteringIsTheGuardsAnswer()

	/**
	 * The bytes come from the document service; a failing read is null, not an exception.
	 *
	 * @return void
	 */
	public function testContentIsReadAndAFailingReadIsNull(): void {
		$this->documents->method('getContent')->willReturnCallback(
			static function (string $uuid, string $fileName): string {
				if ($uuid === 'broken') {
					throw new RuntimeException('disk');
				}

				return $uuid . ':' . $fileName;
			}
		);

		self::assertSame('doc-1:brief.pdf', $this->reader()->contentFor(uuid: 'doc-1', fileName: 'brief.pdf'));
		self::assertNull($this->reader()->contentFor(uuid: 'broken', fileName: 'brief.pdf'));
	}//end testContentIsReadAndAFailingReadIsNull()

	/**
	 * The exception names are put on the rows when wired, and the rows pass unchanged when not.
	 *
	 * @return void
	 */
	public function testAccessExceptionNamesAreAddedWhenWired(): void {
		$rows  = [['id' => 'a']];
		$names = $this->createMock(AccessExceptionNames::class);
		$names->expects($this->once())->method('withNames')->with($rows)->willReturn([['id' => 'a', 'accessException' => ['names' => ['Hanna']]]]);

		self::assertSame([['id' => 'a', 'accessException' => ['names' => ['Hanna']]]], $this->reader(names: $names)->withAccessExceptionNames(documents: $rows));
		self::assertSame($rows, $this->reader()->withAccessExceptionNames(documents: $rows));
	}//end testAccessExceptionNamesAreAddedWhenWired()
}//end class
