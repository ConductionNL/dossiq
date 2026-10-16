<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The listener routes node events to the projection and never throws out
 * of a Files app request.
 *
 * @spec openspec/specs/document-projection/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Dossiq\Listener\CaseFolderNodeListener;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CaseFolderNodeListenerTest extends TestCase {
	private const IN_TREE = '/admin/files/Open Registers/Dossiq Case Management Register/case-1/x.pdf';
	private const OUTSIDE = '/admin/files/Documents/x.pdf';

	/** @var DocumentProjectionService&MockObject The projection double. */
	private DocumentProjectionService&MockObject $projection;

	/** @var LoggerInterface&MockObject Where a failure lands. */
	private LoggerInterface&MockObject $logger;

	/** @var CaseFolderNodeListener The listener under test. */
	private CaseFolderNodeListener $listener;

	/**
	 * A listener over a projection double that knows the register tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->projection = $this->createMock(originalClassName: DocumentProjectionService::class);
		$this->projection->method('isUnderRegisterTree')->willReturnCallback(
			static fn (string $path): bool => str_contains($path, '/Open Registers/')
		);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new CaseFolderNodeListener(projection: $this->projection, logger: $this->logger);
	}//end setUp()

	/**
	 * A file double at a path.
	 *
	 * @param string $path The node path.
	 *
	 * @return File&MockObject The file.
	 */
	private function file(string $path): File&MockObject {
		$node = $this->createMock(originalClassName: File::class);
		$node->method('getPath')->willReturn($path);
		return $node;
	}//end file()

	/**
	 * @return void
	 */
	public function testACreatedFileUnderTheTreeIsProjected(): void {
		$node = $this->file(path: self::IN_TREE);
		$this->projection->expects($this->once())->method('projectNode')->with($node);

		$this->listener->handle(event: new NodeCreatedEvent(node: $node));
	}//end testACreatedFileUnderTheTreeIsProjected()

	/**
	 * @return void
	 */
	public function testAWrittenFileUnderTheTreeIsProjected(): void {
		$node = $this->file(path: self::IN_TREE);
		$this->projection->expects($this->once())->method('projectNode')->with($node);

		$this->listener->handle(event: new NodeWrittenEvent(node: $node));
	}//end testAWrittenFileUnderTheTreeIsProjected()

	/**
	 * @return void
	 */
	public function testAFolderIsNotADocument(): void {
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('getPath')->willReturn('/admin/files/Open Registers/Dossiq Case Management Register/case-1/scans');
		$this->projection->expects($this->never())->method('projectNode');
		$this->projection->expects($this->never())->method('retireNode');

		$this->listener->handle(event: new NodeCreatedEvent(node: $folder));
		$this->listener->handle(event: new NodeDeletedEvent(node: $folder));
	}//end testAFolderIsNotADocument()

	/**
	 * @return void
	 */
	public function testAFileOutsideTheTreeCostsNothing(): void {
		$node = $this->file(path: self::OUTSIDE);
		$this->projection->expects($this->never())->method('projectNode');
		$this->projection->expects($this->never())->method('retireNode');
		$this->projection->expects($this->never())->method('rehomeNode');

		$this->listener->handle(event: new NodeCreatedEvent(node: $node));
		$this->listener->handle(event: new NodeDeletedEvent(node: $node));
		$this->listener->handle(event: new NodeRenamedEvent(source: $this->file(path: self::OUTSIDE), target: $node));
	}//end testAFileOutsideTheTreeCostsNothing()

	/**
	 * @return void
	 */
	public function testADeletedFileIsRetired(): void {
		$node = $this->file(path: self::IN_TREE);
		$this->projection->expects($this->once())->method('retireNode')->with($node);

		$this->listener->handle(event: new NodeDeletedEvent(node: $node));
	}//end testADeletedFileIsRetired()

	/**
	 * @return void
	 */
	public function testARenameHandsOverSourceAndTarget(): void {
		$source = $this->file(path: self::IN_TREE);
		$target = $this->file(path: '/admin/files/Open Registers/Dossiq Case Management Register/case-1/y.pdf');
		$this->projection->expects($this->once())->method('rehomeNode')->with($target, self::IN_TREE);

		$this->listener->handle(event: new NodeRenamedEvent(source: $source, target: $target));
	}//end testARenameHandsOverSourceAndTarget()

	/**
	 * @return void
	 */
	public function testAMoveOutOfTheTreeStillReachesTheProjection(): void {
		// The source was a document; the projection decides what leaving means.
		$source = $this->file(path: self::IN_TREE);
		$target = $this->file(path: self::OUTSIDE);
		$this->projection->expects($this->once())->method('rehomeNode')->with($target, self::IN_TREE);

		$this->listener->handle(event: new NodeRenamedEvent(source: $source, target: $target));
	}//end testAMoveOutOfTheTreeStillReachesTheProjection()

	/**
	 * @return void
	 */
	public function testAFailingProjectionIsLoggedAndNeverThrown(): void {
		$node = $this->file(path: self::IN_TREE);
		$this->projection->method('projectNode')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains(string: 'register down'),
			$this->arrayHasKey(key: 'exception')
		);

		$this->listener->handle(event: new NodeCreatedEvent(node: $node));
		$this->addToAssertionCount(count: 1);
	}//end testAFailingProjectionIsLoggedAndNeverThrown()

	/**
	 * @return void
	 */
	public function testTheRegistrarWiresAllFourNodeEvents(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$seen = [];
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$seen): void {
				if ($listener === CaseFolderNodeListener::class) {
					$seen[] = $event;
				}
			}
		);

		(new ObjectListenerRegistrar())->register(context: $context);

		sort($seen);
		$expected = [NodeCreatedEvent::class, NodeDeletedEvent::class, NodeRenamedEvent::class, NodeWrittenEvent::class];
		sort($expected);
		$this->assertSame(expected: $expected, actual: $seen);
	}//end testTheRegistrarWiresAllFourNodeEvents()

	/**
	 * An object service double that records whether the callable ran as the system.
	 *
	 * @return object The double, with a public `$asSystem` flag and `$elevations` count.
	 */
	private function objectService(): object {
		return new class {
			public bool $asSystem = false;
			public int $elevations = 0;

			/**
			 * Mimic ObjectService::runAsSystem().
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it returns.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->elevations++;
				$this->asSystem = true;
				try {
					return $operation();
				} finally {
					$this->asSystem = false;
				}
			}
		};
	}//end objectService()

	/**
	 * A listener over this projection double, with a session holding the given user.
	 *
	 * @param IUser|null $user   The signed-in user, null for none.
	 * @param object     $or     The object service double.
	 *
	 * @return CaseFolderNodeListener
	 */
	private function listenerFor(?IUser $user, object $or): CaseFolderNodeListener {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->projection->method('asSystem')->willReturnCallback(
			static fn (callable $operation): mixed => $or->runAsSystem($operation)
		);

		return new CaseFolderNodeListener(projection: $this->projection, logger: $this->logger, userSession: $session);
	}//end listenerFor()

	/**
	 * A file written with nobody signed in (an install, a job) is projected as the system.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
	 */
	public function testAFileWrittenWithoutAUserIsProjectedAsTheSystem(): void {
		$or = $this->objectService();
		$node = $this->file(path: self::IN_TREE);
		$ranAsSystem = null;
		$this->projection->expects($this->once())->method('projectNode')->willReturnCallback(
			static function () use ($or, &$ranAsSystem): ?array {
				$ranAsSystem = $or->asSystem;
				return null;
			}
		);

		$this->listenerFor(user: null, or: $or)->handle(event: new NodeCreatedEvent(node: $node));

		$this->assertTrue($ranAsSystem);
	}//end testAFileWrittenWithoutAUserIsProjectedAsTheSystem()

	/**
	 * A file a signed-in user writes is projected as that user, never elevated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
	 */
	public function testAFileAUserWritesIsProjectedAsThatUser(): void {
		$or = $this->objectService();
		$node = $this->file(path: self::IN_TREE);
		$this->projection->expects($this->once())->method('projectNode');
		$this->projection->expects($this->once())->method('retireNode');

		$listener = $this->listenerFor(user: $this->createMock(originalClassName: IUser::class), or: $or);
		$listener->handle(event: new NodeWrittenEvent(node: $node));
		$listener->handle(event: new NodeDeletedEvent(node: $node));

		$this->assertSame(0, $or->elevations);
	}//end testAFileAUserWritesIsProjectedAsThatUser()

	/**
	 * A file outside the tree is not elevated either: it is not projected at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
	 */
	public function testAFileOutsideTheTreeIsNeverElevated(): void {
		$or = $this->objectService();
		$this->projection->expects($this->never())->method('projectNode');

		$this->listenerFor(user: null, or: $or)->handle(event: new NodeCreatedEvent(node: $this->file(path: self::OUTSIDE)));

		$this->assertSame(0, $or->elevations);
	}//end testAFileOutsideTheTreeIsNeverElevated()
}//end class
