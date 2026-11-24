<?php

/**
 * Unit tests for CaseFileEditorController.
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

namespace OCA\OpenRegister\Files\Service\File {
	if (class_exists(UserShareGrant::class) === false) {
		/**
		 * Stand-in for OpenRegister's grant: the node, the user and the permissions.
		 */
		final class UserShareGrant {
			/**
			 * Constructor.
			 *
			 * @param mixed  $node        The node.
			 * @param string $userId      The user.
			 * @param int    $permissions The permissions.
			 */
			public function __construct(
				public readonly mixed $node,
				public readonly string $userId,
				public readonly int $permissions = 31,
			) {
			}
		}
	}
}

namespace OCA\Dossiq\Tests\Unit\Controller {

	use OCA\Dossiq\Controller\CaseFileEditorController;
	use OCP\App\IAppManager;
	use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
	use OCA\Dossiq\Kernel\Contracts\DocumentWriteGateInterface;
	use OCA\Dossiq\Kernel\Contracts\FileDocumentLookupInterface;
	use OCP\AppFramework\Http;
	use OCP\AppFramework\Http\JSONResponse;
	use OCP\Files\File;
	use OCP\Files\Folder;
	use OCP\Files\IRootFolder;
	use OCP\IRequest;
	use OCP\IURLGenerator;
	use OCP\IUser;
	use OCP\IUserSession;
	use OCP\Share\IManager as IShareManager;
	use OCP\Share\IShare;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;

	/**
	 * Tests that a case file is shared for editing only after every check.
	 */
	final class CaseFileEditorControllerTest extends TestCase {

		private const CASE_ID = 'd0d1386f-8bc6-4759-b5c6-33f552faf2bd';

		private CaseAccessGuardInterface $guard;

		private DocumentWriteGateInterface $writeGate;

		private FileDocumentLookupInterface $documents;

		private IRootFolder $rootFolder;

		private IUserSession $session;

		private object $sharing;

		private object $restriction;

		private string $mime = 'text/markdown';

		private bool $officeEnabled = true;

		/**
		 * The share manager the controller resolves through the container.
		 *
		 * @var IShareManager
		 */
		private IShareManager $shareManager;

		/**
		 * Set up the collaborators: a caller who may change the case and a file in its folder.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('pieter');
			$this->session = $this->createMock(IUserSession::class);
			$this->session->method('getUser')->willReturn($user);
			$this->guard = $this->createMock(CaseAccessGuardInterface::class);
			$this->guard->method('hasCaseMutationAccess')->willReturn(true);
			$this->writeGate = $this->createMock(DocumentWriteGateInterface::class);
			$this->documents = $this->createMock(FileDocumentLookupInterface::class);
			$this->documents->method('recordIdOfFile')->willReturn('');
			$this->rootFolder = $this->createMock(IRootFolder::class);
			$this->withFile(path: '/openregister/files/Open Registers/Dossiq Register/' . self::CASE_ID . '/report.docx');
			$this->sharing = new class {
				public array $grants = [];

				public function shareFileWithUser(object $grant): void {
					$this->grants[] = $grant;
				}
			};
			$this->shareManager = $this->createMock(originalClassName: IShareManager::class);
			$this->shareManager->method('getSharesBy')->willReturn([]);
			$this->restriction = new class {
				public bool $readable = true;

				public function mayRead(int $fileId): bool {
					return $this->readable;
				}
			};
		}

		/**
		 * The store answers the file id with a file at this path.
		 *
		 * @param string $path The file's path.
		 *
		 * @return void
		 */
		private function withFile(string $path): void {
			$file = $this->createMock(File::class);
			$file->method('getPath')->willReturn($path);
			$file->method('getId')->willReturn(42);
			$file->method('getMimetype')->willReturn($this->mime);
			$folder = $this->createMock(Folder::class);
			$folder->method('getFirstNodeById')->willReturn($file);
			$this->rootFolder = $this->createMock(IRootFolder::class);
			$this->rootFolder->method('getUserFolder')->with('openregister')->willReturn($folder);
		}

		/**
		 * The controller over the collaborators.
		 *
		 * @return CaseFileEditorController
		 */
		private function controller(): CaseFileEditorController {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				fn (string $id) => match ($id) {
					CaseFileEditorController::SHARING_HANDLER => $this->sharing,
					IShareManager::class => $this->shareManager,
					default => $this->restriction,
				}
			);
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRoute')->willReturnCallback(
				fn (string $route) => $route === 'onlyoffice.editor.index' ? '/apps/onlyoffice/42' : '/index.php/f/42'
			);
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isEnabledForUser')->willReturn($this->officeEnabled);

			return new CaseFileEditorController(
				request: $this->createMock(IRequest::class),
				caseAccessGuard: $this->guard,
				writeGate: $this->writeGate,
				documents: $this->documents,
				rootFolder: $this->rootFolder,
				userSession: $this->session,
				urlGenerator: $urls,
				appManager: $apps,
				container: $container,
				logger: $this->createMock(LoggerInterface::class),
			);
		}

		/**
		 * A store share of the file for this user, with the given status.
		 *
		 * @param string $sharedWith The recipient.
		 * @param int    $status     The share's status.
		 *
		 * @return IShare
		 */
		private function share(string $sharedWith, int $status): IShare {
			$share = $this->createMock(originalClassName: IShare::class);
			$share->method('getSharedWith')->willReturn($sharedWith);
			$share->method('getStatus')->willReturn($status);
			return $share;
		}

		/**
		 * A pending share is accepted for the caller, so the editor finds the file in the caller's files.
		 *
		 * @return void
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		public function testAcceptsAPendingShareForTheCaller(): void {
			$pending = $this->share(sharedWith: 'pieter', status: IShare::STATUS_PENDING);
			$other = $this->share(sharedWith: 'anna', status: IShare::STATUS_PENDING);
			$this->shareManager = $this->createMock(originalClassName: IShareManager::class);
			$this->shareManager->expects(self::once())->method('getSharesBy')
				->with(CaseFileEditorController::STORE_USER, IShare::TYPE_USER)
				->willReturn([$other, $pending]);
			$this->shareManager->expects(self::once())->method('acceptShare')->with($pending, 'pieter');

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		}

		/**
		 * A share the caller already accepted is left as it is.
		 *
		 * @return void
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		public function testLeavesAnAcceptedShareAlone(): void {
			$this->shareManager = $this->createMock(originalClassName: IShareManager::class);
			$this->shareManager->method('getSharesBy')->willReturn([$this->share(sharedWith: 'pieter', status: IShare::STATUS_ACCEPTED)]);
			$this->shareManager->expects(self::never())->method('acceptShare');

			self::assertSame(expected: Http::STATUS_OK, actual: $this->controller()->open(caseId: self::CASE_ID, fileId: 42)->getStatus());
		}

		/**
		 * A share that cannot be accepted does not stop the link.
		 *
		 * @return void
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		public function testAnAcceptFailureStillAnswersTheLink(): void {
			$this->shareManager = $this->createMock(originalClassName: IShareManager::class);
			$this->shareManager->method('getSharesBy')->willReturn([$this->share(sharedWith: 'pieter', status: IShare::STATUS_PENDING)]);
			$this->shareManager->method('acceptShare')->willThrowException(new \RuntimeException(message: 'no'));

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
			self::assertSame(expected: '/index.php/f/42', actual: $response->getData()['url']);
		}

		/**
		 * A caller who may change the case gets the file shared for read and update, and the link.
		 *
		 * @return void
		 */
		public function testSharesTheFileForEditingAndAnswersTheLink(): void {
			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(Http::STATUS_OK, $response->getStatus());
			self::assertSame('/index.php/f/42', $response->getData()['url']);
			self::assertCount(1, $this->sharing->grants);
			self::assertSame('pieter', $this->sharing->grants[0]->userId);
			self::assertSame(CaseFileEditorController::EDIT_PERMISSIONS, $this->sharing->grants[0]->permissions);
		}

		/**
		 * An office document opens on the office editor's own page.
		 *
		 * @return void
		 */
		public function testAnOfficeDocumentOpensInTheOfficeEditor(): void {
			$this->mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$this->withFile(path: '/openregister/files/Open Registers/Dossiq Register/' . self::CASE_ID . '/report.docx');

			self::assertSame('/apps/onlyoffice/42', $this->controller()->open(caseId: self::CASE_ID, fileId: 42)->getData()['url']);
		}

		/**
		 * Without the office editor an office document opens in the Files app.
		 *
		 * @return void
		 */
		public function testWithoutTheOfficeEditorTheFilesAppOpensIt(): void {
			$this->mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$this->officeEnabled = false;
			$this->withFile(path: '/openregister/files/Open Registers/Dossiq Register/' . self::CASE_ID . '/report.docx');

			self::assertSame('/index.php/f/42', $this->controller()->open(caseId: self::CASE_ID, fileId: 42)->getData()['url']);
		}

		/**
		 * A caller who may not change the case is refused and nothing is shared.
		 *
		 * @return void
		 */
		public function testRefusesACallerWhoMayNotChangeTheCase(): void {
			$this->guard = $this->createMock(CaseAccessGuardInterface::class);
			$this->guard->method('hasCaseMutationAccess')->willReturn(false);

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame([], $this->sharing->grants);
		}

		/**
		 * A file outside the case's folder is not found and nothing is shared.
		 *
		 * @return void
		 */
		public function testRefusesAFileOfAnotherCase(): void {
			$this->withFile(path: '/openregister/files/Open Registers/Dossiq Register/another-case/report.docx');

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			self::assertSame([], $this->sharing->grants);
		}

		/**
		 * A restricted file the caller may not read is refused and nothing is shared.
		 *
		 * @return void
		 */
		public function testRefusesARestrictedFile(): void {
			$this->restriction->readable = false;

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame([], $this->sharing->grants);
		}

		/**
		 * A document whose other case refuses the write is refused and nothing is shared.
		 *
		 * @return void
		 */
		public function testRefusesADocumentAnotherCaseMayNotChange(): void {
			$this->documents = $this->createMock(FileDocumentLookupInterface::class);
			$this->documents->method('recordIdOfFile')->willReturn('doc-1');
			$this->writeGate->method('refusedWrite')->willReturn(new JSONResponse(['error' => 'no'], Http::STATUS_FORBIDDEN));

			$response = $this->controller()->open(caseId: self::CASE_ID, fileId: 42);

			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame([], $this->sharing->grants);
		}

		/**
		 * No signed-in caller is refused.
		 *
		 * @return void
		 */
		public function testRefusesWithoutACaller(): void {
			$this->session = $this->createMock(IUserSession::class);
			$this->session->method('getUser')->willReturn(null);

			self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->open(caseId: self::CASE_ID, fileId: 42)->getStatus());
		}
	}
}
