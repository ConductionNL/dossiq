<?php

/**
 * The redaction routes are the case handler's, and a refusal keeps its status.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentRedactionController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Redaction\DocumentRedactionService;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\DocumentRedactionController
 */
class DocumentRedactionControllerTest extends TestCase {

	/**
	 * The redaction step.
	 *
	 * @var DocumentRedactionService&MockObject
	 */
	private DocumentRedactionService&MockObject $redactions;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->redactions = $this->createMock(DocumentRedactionService::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller, for a handler or not.
	 *
	 * @param bool $handler Whether the signed-in user handles the case.
	 *
	 * @return DocumentRedactionController
	 */
	private function controller(bool $handler): DocumentRedactionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pjansen');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$access = $this->createMock(CaseAccessGuard::class);
		$access->method('hasCaseMutationAccess')->willReturn($handler);

		return new DocumentRedactionController(
			appName: 'dossiq',
			request: $this->request,
			redactions: $this->redactions,
			caseAccess: $access,
			uploads: new DossierUploadHandler(
				fileService: $this->createMock(\OCA\Dossiq\Service\ZaakdossierService::class),
				caseAccessGuard: $access,
				quotaGate: $this->createMock(\OCA\Dossiq\Service\QuotaGate::class)
			),
			userSession: $session,
			l10n: new SourceL10n(),
		);
	}//end controller()

	/**
	 * Somebody who does not handle the case is refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testANonHandlerIsRefusedBeforeTheServiceRuns(): void {
		$this->redactions->expects($this->never())->method($this->anything());
		$controller = $this->controller(handler: false);

		foreach ([
			$controller->index(id: 'case-1'),
			$controller->detect(id: 'case-1', documentRef: 'doc-1'),
			$controller->check(id: 'case-1', documentRef: 'doc-1'),
			$controller->redact(id: 'case-1', documentRef: 'doc-1'),
			$controller->upload(id: 'case-1', documentRef: 'doc-1'),
		] as $response) {
			self::assertSame(403, $response->getStatus());
			self::assertSame('not-the-handler', $response->getData()['error']);
		}
	}//end testANonHandlerIsRefusedBeforeTheServiceRuns()

	/**
	 * The handler's check reaches the service with the posted list and their own uid.
	 *
	 * @return void
	 */
	public function testTheHandlersCheckCarriesTheirName(): void {
		$entities = [['type' => 'PERSON', 'value' => 'Sanne de Vries']];
		$this->request->method('getParam')->willReturnMap([['entities', [], $entities]]);
		$this->redactions->expects($this->once())->method('check')
			->with('case-1', 'doc-1', $entities, 'pjansen')
			->willReturn(['checkedBy' => 'pjansen']);

		$response = $this->controller(handler: true)->check(id: 'case-1', documentRef: 'doc-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['redaction' => ['checkedBy' => 'pjansen']], $response->getData());
	}//end testTheHandlersCheckCarriesTheirName()

	/**
	 * A refusal answers with its own status and sentence.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatusAndSentence(): void {
		$this->redactions->method('redact')->willThrowException(
			new RefusedException(rule: 'not-checked', sentence: 'Check the passages first.')
		);

		$response = $this->controller(handler: true)->redact(id: 'case-1', documentRef: 'doc-1');

		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'not-checked', 'message' => 'Check the passages first.'], $response->getData());
	}//end testARefusalKeepsItsStatusAndSentence()

	/**
	 * An executable is not a redacted version.
	 *
	 * @return void
	 */
	public function testAnExecutableUploadIsRefused(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'dqx');
		file_put_contents($tmp, "MZ\x90\x00binary");
		$this->request->method('getUploadedFile')->willReturn(['name' => 'gelakt.exe', 'tmp_name' => $tmp, 'type' => '', 'size' => 8]);
		$this->redactions->expects($this->never())->method('upload');

		$response = $this->controller(handler: true)->upload(id: 'case-1', documentRef: 'doc-1');
		unlink($tmp);

		self::assertSame(422, $response->getStatus());
		self::assertSame('executable-upload', $response->getData()['error']);
	}//end testAnExecutableUploadIsRefused()

	/**
	 * An uploaded file's bytes reach the service.
	 *
	 * @return void
	 */
	public function testAnUploadedFileReachesTheService(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'dqx');
		file_put_contents($tmp, '%PDF-gelakt');
		$this->request->method('getUploadedFile')->willReturn(['name' => 'gelakt.pdf', 'tmp_name' => $tmp, 'type' => 'application/pdf', 'size' => 11]);
		$this->redactions->expects($this->once())->method('upload')
			->with('case-1', 'doc-1', 'gelakt.pdf', '%PDF-gelakt', 'pjansen')
			->willReturn(['redactedDocumentRef' => 'copy-1', 'redaction' => []]);

		$response = $this->controller(handler: true)->upload(id: 'case-1', documentRef: 'doc-1');
		unlink($tmp);

		self::assertSame(200, $response->getStatus());
		self::assertSame('copy-1', $response->getData()['redactedDocumentRef']);
	}//end testAnUploadedFileReachesTheService()
}//end class
