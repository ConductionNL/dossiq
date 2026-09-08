<?php

/**
 * A document created over the ZGW DRC API carries its Nextcloud file id.
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
 * @spec openspec/specs/document-zaakdossier/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DrcController;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ObjectService stub for the DRC create path.
 *
 * The signature mirrors OpenRegister's own, not this caller's convenience.
 */
interface DrcStampObjectServiceStub {

	/**
	 * Save or update an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid The object UUID to overwrite.
	 *
	 * @return mixed The saved object row.
	 */
	public function saveObject(string $register, string $schema, array $object, ?string $uuid = null): mixed;
}//end interface

/**
 * The file id is what three delegated surfaces resolve a document THROUGH.
 *
 * `openInFiles()` and `VersionHistoryPanel` both return early on a falsy
 * `fileId`, and the Files comments sidebar hangs off the same node. Only the
 * upload path ever stamped one, so a document created over the DRC API
 * rendered an Open in Files action, a Version history action and a comments
 * sidebar that all did nothing and said nothing.
 *
 * The old guard is why. It was keyed on `fileSize` alone, so a caller that
 * supplied `bestandsomvang` — which the ZGW contract expects them to — skipped
 * the second write entirely and the id never landed. That is the case this
 * suite pins, deliberately, rather than the easier one where fileSize is
 * absent and the write happened to occur anyway.
 *
 * @covers \OCA\Dossiq\Controller\DrcController
 */
class DrcFileIdStampContractTest extends TestCase {

	/** @var IRequest&MockObject The request. */
	private IRequest $request;

	/** @var ZgwService&MockObject The ZGW facade. */
	private ZgwService $zgwService;

	/** @var DrcController The controller under test. */
	private DrcController $controller;

	/** @var array<int, array<string, mixed>> Every object handed to saveObject. */
	private array $saved = [];

	/**
	 * Build the controller over a fully stubbed ZGW facade.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->zgwService = $this->createMock(ZgwService::class);
		$this->zgwService->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new DrcController(
			appName: 'dossiq',
			request: $this->request,
			zgwService: $this->zgwService,
			l10n: $l10n,
		);
	}//end setUp()

	/**
	 * Wire the whole create path up to the point the stamp happens.
	 *
	 * @param int $fileId The file id the document service resolves, or 0 to throw.
	 * @param array<string, mixed> $storedExtra Extra fields on the row the first save returns.
	 *
	 * @return void
	 */
	private function wireCreate(int $fileId, array $storedExtra = []): void {
		$this->zgwService->method('validateJwtAuth')->willReturn(null);
		$this->zgwService->method('consumerHasScope')->willReturn(true);
		$this->zgwService->method('loadMappingConfig')->willReturn(
			['sourceRegister' => 'drc-register', 'sourceSchema' => 'eio']
		);

		$body = [
			'inhoud' => base64_encode('the bytes'),
			'bestandsnaam' => 'brief.pdf',
			// The ZGW contract expects the size, and supplying it is exactly
			// what used to skip the stamp.
			'bestandsomvang' => 9,
		];
		$this->zgwService->method('getRequestBody')->willReturn($body);

		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturn(['valid' => true, 'enrichedBody' => $body]);
		$this->zgwService->method('getBusinessRulesService')->willReturn($rules);

		// Both mapping factories return an OBJECT, not an array: the stub has to
		// agree with the CALLEE's signature, or it cannot stand in for it.
		$this->zgwService->method('createInboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyInboundMapping')->willReturn(
			[
				'title' => 'Brief',
				'fileName' => 'brief.pdf',
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjecttype' => 'type-1',
			]
		);

		// The row the FIRST save returns: it already carries a fileSize,
		// because the caller supplied bestandsomvang.
		$stored = array_merge(
			[
				'id' => 'eio-1',
				'title' => 'Brief',
				'fileName' => 'brief.pdf',
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'informatieobjecttype' => 'type-1',
				'fileSize' => 9,
			],
			$storedExtra
		);

		$objectService = $this->createMock(DrcStampObjectServiceStub::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (string $register, string $schema, array $object, ?string $uuid = null) use ($stored): array {
				$this->saved[] = $object;
				return $stored;
			}
		);
		$this->zgwService->method('getObjectService')->willReturn($objectService);

		$documentService = $this->createMock(ZgwDocumentService::class);
		$documentService->method('storeBase64')->willReturn(9);
		if ($fileId > 0) {
			$documentService->method('getFileId')->willReturn($fileId);
		} else {
			$documentService->method('getFileId')->willThrowException(
				new RuntimeException('no folder for this document')
			);
		}

		$this->zgwService->method('getDocumentService')->willReturn($documentService);

		$this->zgwService->method('buildBaseUrl')->willReturn('http://localhost/drc');
		$this->zgwService->method('createOutboundMapping')->willReturn(new \stdClass());
		$this->zgwService->method('applyOutboundMapping')->willReturn(['url' => 'http://localhost/drc/eio-1']);
	}//end wireCreate()

	/**
	 * The stamped object, or null when the stamp never happened.
	 *
	 * @return array<string, mixed>|null The second saved object.
	 */
	private function stampedObject(): ?array {
		foreach ($this->saved as $object) {
			if (isset($object['fileId']) === true) {
				return $object;
			}
		}

		return null;
	}//end stampedObject()

	/**
	 * A created document is stamped with the file id it was just written to.
	 *
	 * @return void
	 */
	public function testCreateStampsTheFileIdEvenWhenTheSizeWasSupplied(): void {
		$this->wireCreate(fileId: 4711);

		$this->controller->create(resource: 'enkelvoudiginformatieobjecten');

		$stamped = $this->stampedObject();
		$this->assertNotNull(
			$stamped,
			'the create path must write the file id back onto the document'
		);
		$this->assertSame(4711, $stamped['fileId']);
	}//end testCreateStampsTheFileIdEvenWhenTheSizeWasSupplied()

	/**
	 * The stamp writes the WHOLE object, never just the field.
	 *
	 * `saveObject()` REPLACES; a partial write drops the four properties the
	 * informatieobject schema requires and OpenRegister refuses it. That is the
	 * defect #1960 fixed on the upload path, and writing the same partial here
	 * would reintroduce it on this one.
	 *
	 * @return void
	 */
	public function testTheStampCarriesTheRequiredPropertiesWithIt(): void {
		$this->wireCreate(fileId: 4711);

		$this->controller->create(resource: 'enkelvoudiginformatieobjecten');

		$stamped = $this->stampedObject();
		$this->assertNotNull($stamped);
		foreach (['title', 'fileName', 'vertrouwelijkheidaanduiding', 'informatieobjecttype'] as $required) {
			$this->assertArrayHasKey(
				$required,
				$stamped,
				'the stamp dropped a required property, which OpenRegister refuses'
			);
		}
	}//end testTheStampCarriesTheRequiredPropertiesWithIt()

	/**
	 * A document that already carries the right id is not written again.
	 *
	 * @return void
	 */
	public function testAnAlreadyStampedDocumentIsNotRewritten(): void {
		$this->wireCreate(fileId: 4711, storedExtra: ['fileId' => 4711]);

		$this->controller->create(resource: 'enkelvoudiginformatieobjecten');

		$this->assertCount(
			1,
			$this->saved,
			'nothing changed, so the second save is pure write amplification'
		);
	}//end testAnAlreadyStampedDocumentIsNotRewritten()

	/**
	 * An unresolvable file id degrades the metadata, it does not fail the create.
	 *
	 * The document has already been written at this point. Throwing here would
	 * lose a document the caller was told nothing about, which is strictly
	 * worse than a document that is missing one convenience field.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFileIdDoesNotFailTheCreate(): void {
		$this->wireCreate(fileId: 0);

		$response = $this->controller->create(resource: 'enkelvoudiginformatieobjecten');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertNull($this->stampedObject(), 'nothing to stamp, so nothing is stamped');
	}//end testAnUnresolvableFileIdDoesNotFailTheCreate()
}//end class
