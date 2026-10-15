<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A file in a case's folder is a document: this listener keeps the ZGW
 * record in step with the file.
 *
 * Nextcloud raises a node event for every write, rename and delete under
 * every folder, so the first thing this listener does is get out of the way:
 * folders are not documents, and a node outside the `Open Registers` tree is
 * none of dossiq's business. What remains is handed to
 * DocumentProjectionService, which decides whether the node sits under a
 * case and what the record should say. Nothing thrown here may reach the
 * Files app: a failing projection is logged, and the file the handler just
 * saved stays saved.
 *
 * A file written without a signed-in user (an install, a repair, a
 * background job) is projected as the system: there is nobody whose rights
 * the record could be checked against, and the file is already in the case
 * folder.
 *
 * @spec openspec/specs/document-projection/spec.md
 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
 *
 * @template-implements IEventListener<Event>
 */
class CaseFolderNodeListener implements IEventListener {

	/**
	 * @param DocumentProjectionService $projection The service that owns the record.
	 * @param LoggerInterface $logger Where a failed projection is reported.
	 * @param IUserSession|null $userSession Who wrote the file; nobody means the system.
	 */
	public function __construct(
		private readonly DocumentProjectionService $projection,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Run a projection as the user who wrote the file, or as the system when nobody is signed in.
	 *
	 * @param callable $operation The projection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-file-written-without-a-user-is-projected-as-the-system/specs/document-projection/spec.md#requirement-a-file-written-without-a-signed-in-user-is-projected-as-the-system-req-dpr-006
	 */
	private function asWriter(callable $operation): void {
		if ($this->userSession === null || $this->userSession->getUser() !== null) {
			$operation();
			return;
		}

		$this->projection->asSystem(operation: $operation);
	}//end asWriter()

	/**
	 * Hand a file event under the register tree to the projection.
	 *
	 * @param Event $event The node event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function handle(Event $event): void {
		try {
			$this->dispatch(event: $event);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq documents: the projection of a file event failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e],
			);
		}
	}//end handle()

	/**
	 * Route one event to the projection call it means.
	 *
	 * @param Event $event The node event.
	 *
	 * @return void
	 */
	private function dispatch(Event $event): void {
		if ($event instanceof NodeRenamedEvent) {
			$this->onRenamed(event: $event);
			return;
		}

		if ($event instanceof NodeDeletedEvent) {
			$node = $this->fileInTree(node: $event->getNode());
			if ($node !== null) {
				$this->asWriter(operation: fn () => $this->projection->retireNode(node: $node));
			}

			return;
		}

		if ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
			$node = $this->fileInTree(node: $event->getNode());
			if ($node !== null) {
				$this->asWriter(operation: fn () => $this->projection->projectNode(node: $node));
			}
		}
	}//end dispatch()

	/**
	 * A rename in place updates the record; a move between folders re-homes it.
	 *
	 * @param NodeRenamedEvent $event The rename event, source and target.
	 *
	 * @return void
	 */
	private function onRenamed(NodeRenamedEvent $event): void {
		$target = $event->getTarget();
		if (($target instanceof File) === false) {
			return;
		}

		$sourcePath = $event->getSource()->getPath();
		$inTree = $this->projection->isUnderRegisterTree(path: $sourcePath)
			|| $this->projection->isUnderRegisterTree(path: $target->getPath());
		if ($inTree === false) {
			return;
		}

		$this->asWriter(operation: fn () => $this->projection->rehomeNode(node: $target, sourcePath: $sourcePath));
	}//end onRenamed()

	/**
	 * The node as a file under the register tree, or null for anything else.
	 *
	 * @param Node $node The event's node.
	 *
	 * @return File|null The file, when it is one and lies under the tree.
	 */
	private function fileInTree(Node $node): ?File {
		if (($node instanceof File) === false) {
			return null;
		}

		if ($this->projection->isUnderRegisterTree(path: $node->getPath()) === false) {
			return null;
		}

		return $node;
	}//end fileInTree()
}//end class
