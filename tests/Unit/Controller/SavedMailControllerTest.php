<?php

/**
 * Unit tests for reading a saved mail file on a case.
 *
 * 🔴 THE CONTROLLER WAS THE ONE HALF OF THIS CHANGE NOBODY ASSERTED. The
 * reader ({@see \OCA\Dossiq\Service\Email\SavedMailImport}) has its own six
 * arms and the browser handler has a vitest suite, and between them sat an
 * endpoint with an authorization guard, a size cap and a storage lookup that
 * no test reached. A guard with no test is the shape this repo has shipped
 * before: it passes review by being written down.
 *
 * 🔴 THE GUARD IS PROBED WITH THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE
 * REFUSED, and it is the MUTATION guard rather than the read one. Reading a
 * file as a message writes a message onto the case, so a handler who may see
 * the case but not change it must be refused; asserting an admin succeeds
 * would prove almost nothing.
 *
 * 🔴 THE ORDER OF THE REFUSALS IS PART OF THE CONTRACT. The guard answers
 * before the node is resolved, so a caller who may not touch the case cannot
 * learn from a 404 whether a file id exists.
 *
 * @category Test
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
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SavedMailController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Email\SavedMailImport;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the authorization, the node lookup and the size cap on read.
 *
 * @covers \OCA\Dossiq\Controller\SavedMailController
 * @uses \OCA\Dossiq\Service\Email\SavedMailImport
 *
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */
final class SavedMailControllerTest extends TestCase {

	/**
	 * The reader, doubled: what it answers is its own suite's business.
	 *
	 * @var SavedMailImport&MockObject
	 */
	private SavedMailImport $import;

	/**
	 * Whether this caller may change this case.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The node the caller's folder answers with, or null.
	 *
	 * @var File|null
	 */
	private ?File $node = null;

	/**
	 * The caller, or null for an anonymous request.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * A caller who holds the case and a file that is a small saved message.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->import = $this->getMockBuilder(SavedMailImport::class)
			->disableOriginalConstructor()
			->onlyMethods(['import'])
			->getMock();
		$this->import->method('import')->willReturn(
			[
				'outcome' => SavedMailImport::OUTCOME_IMPORTED,
				'reason' => '',
				'subject' => 'Bezwaar tegen de aanslag',
				'from' => 'aanvrager@voorbeeld.nl',
				'receivedAt' => '2026-09-18T09:00:00+00:00',
			]
		);

		$this->caseAccessGuard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseMutationAccess'])
			->getMock();
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('behandelaar');

		$this->node = $this->file(name: 'bezwaar.eml', size: 2048, content: "From: a@b.nl\r\n\r\ntekst");
	}//end setUp()

	/**
	 * One file node in the caller's folder.
	 *
	 * @param string $name    The file name.
	 * @param int    $size    The size in bytes.
	 * @param string $content What reading it answers.
	 * @param bool   $throws  Whether reading it throws.
	 *
	 * @return File&MockObject The node.
	 */
	private function file(string $name, int $size, string $content, bool $throws = false): File {
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn($name);
		$node->method('getSize')->willReturn($size);
		if ($throws === true) {
			$node->method('getContent')->willThrowException(new RuntimeException('storage is gone'));
		} else {
			$node->method('getContent')->willReturn($content);
		}

		return $node;
	}//end file()

	/**
	 * The controller, built from whatever the test configured.
	 *
	 * @return SavedMailController The surface under test.
	 */
	private function controller(): SavedMailController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);

		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($this->node);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new SavedMailController(
			$this->createMock(IRequest::class),
			$this->import,
			$this->caseAccessGuard,
			$rootFolder,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * An anonymous caller is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->user = null;
		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: 'case-114', fileId: 42);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * 🔴 A handler who may not change this case is refused, and nothing is filed.
	 *
	 * @return void
	 */
	public function testACaseTheCallerMayNotChangeIsRefused(): void {
		$guard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseMutationAccess'])
			->getMock();
		$guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->caseAccessGuard = $guard;

		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: 'case-200', fileId: 42);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACaseTheCallerMayNotChangeIsRefused()

	/**
	 * A request naming no case is refused before the guard is asked.
	 *
	 * @return void
	 */
	public function testARequestWithNoCaseIsRefused(): void {
		$this->caseAccessGuard->expects(self::never())->method('hasCaseMutationAccess');
		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: '   ', fileId: 42);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testARequestWithNoCaseIsRefused()

	/**
	 * A file id the caller's folder does not answer is a 404.
	 *
	 * @return void
	 */
	public function testAFileTheCallerCannotReachIsNotFound(): void {
		$this->node = null;
		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: 'case-114', fileId: 999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFileTheCallerCannotReachIsNotFound()

	/**
	 * A file too large to read answers with a sentence, not an error.
	 *
	 * Twenty megabytes of a saved message is still a saved message, so this is
	 * a `kept` outcome on a 200 rather than a 413: the file is unchanged and
	 * the handler is told why nothing was read.
	 *
	 * @return void
	 */
	public function testAFileOverTheCapIsKeptWithItsOwnSentence(): void {
		$this->node = $this->file(name: 'groot.eml', size: 20971521, content: 'bytes');
		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: 'case-114', fileId: 42);
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(SavedMailImport::OUTCOME_KEPT, $data['outcome']);
		self::assertStringContainsString('too large', $data['reason']);
	}//end testAFileOverTheCapIsKeptWithItsOwnSentence()

	/**
	 * Storage that will not hand over the bytes is a 404, not a 500.
	 *
	 * @return void
	 */
	public function testAFileThatWillNotReadIsNotFound(): void {
		$this->node = $this->file(name: 'bezwaar.eml', size: 2048, content: '', throws: true);
		$this->import->expects(self::never())->method('import');

		$response = $this->controller()->read(caseId: 'case-114', fileId: 42);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFileThatWillNotReadIsNotFound()

	/**
	 * 🔴 The reader is handed the file's own name and bytes, and its answer is
	 * passed through unchanged.
	 *
	 * @return void
	 */
	public function testTheReaderGetsTheFileAndItsAnswerIsPassedThrough(): void {
		$import = $this->getMockBuilder(SavedMailImport::class)
			->disableOriginalConstructor()
			->onlyMethods(['import'])
			->getMock();
		$import->expects(self::once())
			->method('import')
			->with('case-114', 'bezwaar.eml', "From: a@b.nl\r\n\r\ntekst")
			->willReturn(
				[
					'outcome' => SavedMailImport::OUTCOME_IMPORTED,
					'reason' => '',
					'subject' => 'Bezwaar tegen de aanslag',
					'from' => 'aanvrager@voorbeeld.nl',
					'receivedAt' => '2026-09-18T09:00:00+00:00',
				]
			);
		$this->import = $import;

		$response = $this->controller()->read(caseId: ' case-114 ', fileId: 42);
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(SavedMailImport::OUTCOME_IMPORTED, $data['outcome']);
		self::assertSame('Bezwaar tegen de aanslag', $data['subject']);
	}//end testTheReaderGetsTheFileAndItsAnswerIsPassedThrough()

	/**
	 * A `kept` answer from the reader reaches the handler on a 200.
	 *
	 * The browser handler treats anything but `imported` as a failure with a
	 * reason, so the reason has to survive the controller. A 500 here would
	 * be this app reporting its own failure rather than the file's.
	 *
	 * @return void
	 */
	public function testAKeptAnswerReachesTheHandlerWithItsReason(): void {
		$import = $this->getMockBuilder(SavedMailImport::class)
			->disableOriginalConstructor()
			->onlyMethods(['import'])
			->getMock();
		$import->method('import')->willReturn(
			[
				'outcome' => SavedMailImport::OUTCOME_KEPT,
				'reason' => 'Integriq is not installed, and it is what reads a saved mail file.',
				'subject' => '',
				'from' => '',
				'receivedAt' => '',
			]
		);
		$this->import = $import;

		$response = $this->controller()->read(caseId: 'case-114', fileId: 42);
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(SavedMailImport::OUTCOME_KEPT, $data['outcome']);
		self::assertStringContainsString('Integriq is not installed', $data['reason']);
	}//end testAKeptAnswerReachesTheHandlerWithItsReason()

}//end class
