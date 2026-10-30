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

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseFolderNodeListener;
use OCA\Dossiq\Listener\DeclaredAccessExceptionListener;
use OCA\Dossiq\Listener\DocumentJoinHomingListener;
use OCA\Dossiq\Listener\FileRestrictionAdmitListener;
use OCA\Dossiq\Listener\RestrictedDocumentHandlerListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

/**
 * A file in a case's folder is a document: wire the listener that keeps its
 * ZGW record in step.
 *
 * Four Nextcloud node events, one listener, registration only (ADR-106):
 * the listener itself gets out of the way of every node outside the register
 * tree before it reads anything.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentListenerRegistrar {

	/**
	 * The node events a document's file can raise.
	 *
	 * @var list<class-string>
	 */
	public const NODE_EVENTS = [
		NodeCreatedEvent::class,
		NodeWrittenEvent::class,
		NodeRenamedEvent::class,
		NodeDeletedEvent::class,
	];

	/**
	 * Register the case-folder node listener for every node event it reads.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::NODE_EVENTS as $event) {
			$context->registerEventListener(event: $event, listener: CaseFolderNodeListener::class);
		}

		// Document-access-exception: OpenRegister asks whether the case's handler may read a restricted document file.
		$context->registerEventListener(event: FileRestrictionAdmitListener::EVENT, listener: FileRestrictionAdmitListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: RestrictedDocumentHandlerListener::class);
		// An exception stored straight onto a document (an example set, an import) is applied as the writer applies it.
		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: DeclaredAccessExceptionListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: DeclaredAccessExceptionListener::class);

		// A join stored straight into OpenRegister homes its document's file, as the ZRC endpoint does.
		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: DocumentJoinHomingListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: DocumentJoinHomingListener::class);

		// The document review's write guards (verdicts wait for the pages, the stopping rule is fixed).
		(new ReviewListenerRegistrar())->register(context: $context);
	}//end register()
}//end class
