<?php

/**
 * Tests for DocumentJoinHomingListener: a stored document join homes its file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DocumentJoinHomingListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\DocumentJoinHomingListener
 */
class DocumentJoinHomingListenerTest extends TestCase {

	/**
	 * The homes asked for, as [case, record] pairs.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $homed = [];

	/**
	 * The listener under test.
	 *
	 * @var DocumentJoinHomingListener
	 */
	private DocumentJoinHomingListener $listener;

	/**
	 * Wire the listener over a recording homing and a resolver that reads `@self.schema`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$homing = $this->createMock(originalClassName: DocumentJoinHoming::class);
		$homing->method('home')->willReturnCallback(
			function (string $caseUrl, string $informatieobjectUrl): bool {
				$this->homed[] = [$caseUrl, $informatieobjectUrl];
				return true;
			}
		);

		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$this->listener = new DocumentJoinHomingListener(homing: $homing, slugResolver: $resolver);
	}//end setUp()

	/**
	 * A join created straight in OpenRegister homes its document's file into the case.
	 *
	 * @return void
	 */
	public function testACreatedJoinHomesItsFile(): void {
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(record: ['case' => 'case-1', 'informatieobject' => 'doc-1'])));

		$this->assertSame(expected: [['case-1', 'doc-1']], actual: $this->homed);
	}//end testACreatedJoinHomesItsFile()

	/**
	 * A join saved again homes its file too, so joins written before this listener catch up.
	 *
	 * @return void
	 */
	public function testAnUpdatedJoinHomesItsFile(): void {
		$entity = $this->entity(record: ['case' => 'case-1', 'informatieobject' => 'doc-1']);
		$this->listener->handle(event: new ObjectUpdatedEvent($entity, $entity));

		$this->assertSame(expected: [['case-1', 'doc-1']], actual: $this->homed);
	}//end testAnUpdatedJoinHomesItsFile()

	/**
	 * Another schema, or a join without its case or its document, homes nothing.
	 *
	 * @return void
	 */
	public function testAnythingElseHomesNothing(): void {
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(record: ['case' => 'case-1', 'informatieobject' => 'doc-1'], schema: 'case')));
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(record: ['case' => 'case-1'])));
		$this->listener->handle(event: new ObjectCreatedEvent($this->entity(record: ['informatieobject' => 'doc-1'])));

		$this->assertSame(expected: [], actual: $this->homed);
	}//end testAnythingElseHomesNothing()

	/**
	 * A stored object whose serialised form is the record with its schema.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $record, string $schema = 'zaakinformatieobject'): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(array_merge(['@self' => ['id' => 'join-1', 'schema' => $schema]], $record));

		return $entity;
	}//end entity()
}//end class
