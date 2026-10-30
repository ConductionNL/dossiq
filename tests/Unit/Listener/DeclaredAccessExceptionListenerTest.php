<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeclaredAccessExceptionListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Zaakdossier\DeclaredAccessExceptions;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\DeclaredAccessExceptionListener
 *
 * @spec openspec/changes/a-declared-access-exception-is-applied/specs/document-access-exception/spec.md#requirement-an-exception-written-straight-onto-a-document-is-applied-as-the-writer-applies-it
 */
class DeclaredAccessExceptionListenerTest extends TestCase {

	/** @var list<array{0: array<string, mixed>, 1: array<string, mixed>|null}> The applies asked for. */
	private array $applied = [];

	/**
	 * A listener over a recording service; `$fail` makes the service throw.
	 *
	 * @param bool $fail Whether apply throws.
	 *
	 * @return DeclaredAccessExceptionListener
	 */
	private function listener(bool $fail = false): DeclaredAccessExceptionListener {
		$exceptions = $this->createMock(originalClassName: DeclaredAccessExceptions::class);
		$exceptions->method('apply')->willReturnCallback(
			function (array $record, ?array $before) use ($fail): bool {
				if ($fail === true) {
					throw new RuntimeException('file');
				}

				$this->applied[] = [$record, $before];
				return true;
			}
		);
		$resolver = $this->createMock(originalClassName: ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		return new DeclaredAccessExceptionListener(
			exceptions: $exceptions,
			slugResolver: $resolver,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A stored object whose serialised form is the record with its schema.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $schema The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $record, string $schema = 'informatieobject'): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(array_merge(['@self' => ['id' => 'doc-1', 'schema' => $schema]], $record));

		return $entity;
	}//end entity()

	/**
	 * @return void
	 */
	public function testACreatedDocumentIsHandedOverWithItsId(): void {
		$this->listener()->handle(event: new ObjectCreatedEvent($this->entity(record: ['title' => 'Offerte'])));

		$this->assertCount(expectedCount: 1, haystack: $this->applied);
		$this->assertSame(expected: 'doc-1', actual: $this->applied[0][0]['id']);
		$this->assertNull(actual: $this->applied[0][1]);
	}//end testACreatedDocumentIsHandedOverWithItsId()

	/**
	 * @return void
	 */
	public function testAnUpdatedDocumentIsHandedOverWithItsFormerSelf(): void {
		$this->listener()->handle(event: new ObjectUpdatedEvent($this->entity(record: ['fileId' => 751]), $this->entity(record: [])));

		$this->assertSame(expected: 751, actual: $this->applied[0][0]['fileId']);
		$this->assertSame(expected: 'doc-1', actual: $this->applied[0][1]['id']);
	}//end testAnUpdatedDocumentIsHandedOverWithItsFormerSelf()

	/**
	 * @return void
	 */
	public function testAnotherSchemaIsIgnoredAndAFailureDoesNotEscape(): void {
		$this->listener()->handle(event: new ObjectCreatedEvent($this->entity(record: [], schema: 'case')));
		$this->assertSame(expected: [], actual: $this->applied);

		$this->listener(fail: true)->handle(event: new ObjectCreatedEvent($this->entity(record: [])));
		$this->addToAssertionCount(1);
	}//end testAnotherSchemaIsIgnoredAndAFailureDoesNotEscape()
}//end class
