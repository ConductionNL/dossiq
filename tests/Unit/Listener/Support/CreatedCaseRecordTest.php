<?php

/**
 * CreatedCaseRecord Unit Tests.
 *
 * Pins that only a created object of the configured schema that names a case is handed to the listener.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\CreatedCaseRecord;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreatedCaseRecord.
 *
 * @covers \OCA\Dossiq\Listener\Support\CreatedCaseRecord
 * @uses \OCA\Dossiq\Listener\Support\EventObjectPayload
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CreatedCaseRecordTest extends TestCase {

	/**
	 * The reader for schema `contactmoment`, resolving the slug from @self.schema.
	 *
	 * @return CreatedCaseRecord
	 */
	private function reader(): CreatedCaseRecord {
		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): ?string => ($payload['@self']['schema'] ?? null)
		);

		return new CreatedCaseRecord($resolver, 'contactmoment');
	}//end reader()

	/**
	 * A created-object event carrying the given record.
	 *
	 * @param array  $record The stored fields.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $record, string $schema = 'contactmoment'): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(array_merge(['@self' => ['id' => 'cm-1', 'schema' => $schema]], $record));

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A created record of the schema that names a case is returned whole.
	 *
	 * @return void
	 */
	public function testACreatedRecordOfTheSchemaWithACaseIsReturned(): void {
		$payload = $this->reader()->of($this->created(['case' => 'case-1', 'channel' => 'phone']));

		$this->assertSame(
			['@self' => ['id' => 'cm-1', 'schema' => 'contactmoment'], 'case' => 'case-1', 'channel' => 'phone'],
			$payload
		);
	}//end testACreatedRecordOfTheSchemaWithACaseIsReturned()

	/**
	 * Another schema, a missing or blank case, and any other event are ignored.
	 *
	 * @return void
	 */
	public function testEverythingElseIsIgnored(): void {
		$reader = $this->reader();

		$this->assertNull($reader->of($this->created(['case' => 'case-1'], 'note')));
		$this->assertNull($reader->of($this->created([])));
		$this->assertNull($reader->of($this->created(['case' => '   '])));
		$this->assertNull($reader->of(new Event()));
	}//end testEverythingElseIsIgnored()
}//end class
