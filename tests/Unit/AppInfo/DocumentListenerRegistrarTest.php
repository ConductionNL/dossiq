<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseFolderNodeListener;
use OCA\Dossiq\Listener\DeclaredAccessExceptionListener;
use OCA\Dossiq\Listener\DocumentJoinHomingListener;
use OCA\Dossiq\Listener\FileRestrictionAdmitListener;
use OCA\Dossiq\Listener\Registrar\DocumentListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar;
use OCA\Dossiq\Listener\RestrictedDocumentHandlerListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use PHPUnit\Framework\TestCase;

/**
 * The case folder follows every node change, the restriction and access
 * listeners follow the document's writes, and the review listeners come last.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\DocumentListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar
 */
class DocumentListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testTheDocumentListenersThenTheReviewListenersAreRegistered(): void {
		(new ReviewListenerRegistrar())->register(context: $this->recordingContext());
		$review          = $this->listeners;
		$this->listeners = [];

		(new DocumentListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			array_merge(
				[
					[NodeCreatedEvent::class, CaseFolderNodeListener::class, 0],
					[NodeWrittenEvent::class, CaseFolderNodeListener::class, 0],
					[NodeRenamedEvent::class, CaseFolderNodeListener::class, 0],
					[NodeDeletedEvent::class, CaseFolderNodeListener::class, 0],
					[FileRestrictionAdmitListener::EVENT, FileRestrictionAdmitListener::class, 0],
					[ObjectUpdatedEvent::class, RestrictedDocumentHandlerListener::class, 0],
					[ObjectCreatedEvent::class, DeclaredAccessExceptionListener::class, 0],
					[ObjectUpdatedEvent::class, DeclaredAccessExceptionListener::class, 0],
					[ObjectCreatedEvent::class, DocumentJoinHomingListener::class, 0],
					[ObjectUpdatedEvent::class, DocumentJoinHomingListener::class, 0],
				],
				$review
			),
			$this->listeners
		);
		self::assertNotSame([], $review, 'The review registrar registers its own listeners.');
	}//end testTheDocumentListenersThenTheReviewListenersAreRegistered()
}//end class
