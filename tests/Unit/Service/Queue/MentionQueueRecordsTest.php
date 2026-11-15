<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A mention on a case lands on the mentioned person's queue as a record, stays
 * there until it is acknowledged, and a missing register or OpenRegister is
 * reported rather than silently treated as "no mentions".
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\MentionQueueRecords;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Queue\MentionQueueRecords
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MentionQueueRecordsTest extends TestCase {

	/**
	 * Every call the object service received, as [method, arguments].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $calls = [];

	/**
	 * A mention is saved with who, by whom, on what and when; a missing subject type means a case.
	 *
	 * @return void
	 */
	public function testAMentionIsRecorded(): void {
		$records = $this->records(objects: $this->objects(saved: ['id' => 'm-1']));

		$this->assertTrue($records->record(person: 'els', actor: 'piet', subjectType: ' ', subjectId: 'case-1', noteId: 'note-9'));

		$this->assertSame('saveObject', $this->calls[0][0]);
		$args = $this->calls[0][1];
		$this->assertSame(['dossiq', 'queueMention', null], [$args['register'], $args['schema'], $args['uuid']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $args['object']['mentionedAt']);
		unset($args['object']['mentionedAt']);
		$this->assertSame(['person' => 'els', 'actor' => 'piet', 'subjectType' => 'case', 'subjectId' => 'case-1', 'noteId' => 'note-9'], $args['object']);
	}//end testAMentionIsRecorded()

	/**
	 * Without a person or subject nothing is saved; a refused save is not a recorded mention.
	 *
	 * @return void
	 */
	public function testIncompleteOrUnsavedMentionsAreNotRecorded(): void {
		$records = $this->records(objects: $this->objects(saved: 'not an object'));

		$this->assertFalse($records->record(person: ' ', actor: 'piet', subjectType: 'case', subjectId: 'case-1', noteId: ''));
		$this->assertFalse($records->record(person: 'els', actor: 'piet', subjectType: 'case', subjectId: '', noteId: ''));
		$this->assertSame([], $this->calls);
		$this->assertFalse($records->record(person: 'els', actor: 'piet', subjectType: 'advice', subjectId: 'adv-1', noteId: ''));
		$this->assertSame('advice', $this->calls[0][1]['object']['subjectType']);
	}//end testIncompleteOrUnsavedMentionsAreNotRecorded()

	/**
	 * Without a register or OpenRegister the mention is not recorded and the reason is logged.
	 *
	 * @return void
	 */
	public function testAMissingStoreIsLoggedOnRecord(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with(
			$this->logicalOr(
				'Dossiq: could not record a mention on the queue: Dossiq has no register configured yet.',
				'Dossiq: could not record a mention on the queue: OpenRegister is not available, so mentions cannot be read.'
			)
		);

		$this->assertFalse($this->records(objects: $this->objects(saved: []), register: ' ', logger: $logger)->record(person: 'els', actor: 'a', subjectType: 'case', subjectId: 'c', noteId: ''));
		$this->assertFalse($this->records(objects: null, logger: $logger)->record(person: 'els', actor: 'a', subjectType: 'case', subjectId: 'c', noteId: ''));
	}//end testAMissingStoreIsLoggedOnRecord()

	/**
	 * The open mentions are the person's records without an acknowledgement.
	 *
	 * @return void
	 */
	public function testOpenMentionsAreTheUnacknowledgedOnes(): void {
		$rows = [
			['id' => 'm-1', 'person' => 'els'],
			['id' => 'm-2', 'person' => 'els', 'acknowledgedAt' => '2026-11-01T10:00:00+01:00'],
			['id' => 'm-3', 'person' => 'els', 'acknowledgedAt' => ' '],
		];
		$records = $this->records(objects: $this->objects(rows: $rows));

		$this->assertSame([$rows[0], $rows[2]], $records->openFor(userId: 'els'));
		$this->assertSame(['searchObjectsBySlug', ['register' => 'dossiq', 'schema' => 'queueMention', 'filters' => ['person' => 'els', '_limit' => 100]]], $this->calls[0]);
	}//end testOpenMentionsAreTheUnacknowledgedOnes()

	/**
	 * A store failure while reading is raised as a runtime exception, keeping its message.
	 *
	 * @return void
	 */
	public function testAReadFailureIsRaised(): void {
		try {
			$this->records(objects: $this->objects(searchFails: new \LogicException('index offline')))->openFor(userId: 'els');
			$this->fail('The failure was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('index offline', $e->getMessage());
			$this->assertInstanceOf(\LogicException::class, $e->getPrevious());
		}

		$this->expectExceptionObject(new RuntimeException('OpenRegister is not available, so mentions cannot be read.'));
		$this->records(objects: null)->openFor(userId: 'els');
	}//end testAReadFailureIsRaised()

	/**
	 * Acknowledging patches only the acknowledgement moment onto the record.
	 *
	 * @return void
	 */
	public function testAcknowledgePatchesTheMoment(): void {
		$records = $this->records(objects: $this->objects(patched: ['id' => 'm-1']));

		$this->assertFalse($records->acknowledge(recordId: ' '));
		$this->assertSame([], $this->calls);
		$this->assertTrue($records->acknowledge(recordId: 'm-1'));

		[$method, $args] = $this->calls[0];
		$this->assertSame('patchObject', $method);
		$this->assertSame(['m-1', 'dossiq', 'queueMention', ['acknowledgedAt']], [$args['objectId'], $args['register'], $args['schema'], array_keys($args['data'])]);
	}//end testAcknowledgePatchesTheMoment()

	/**
	 * A failed or empty patch is not an acknowledgement; a failure is logged.
	 *
	 * @return void
	 */
	public function testAFailedAcknowledgeIsNotAcknowledged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Dossiq: could not close a mention on the queue: Dossiq has no register configured yet.');

		$this->assertFalse($this->records(objects: $this->objects(patched: null))->acknowledge(recordId: 'm-1'));
		$this->assertFalse($this->records(objects: $this->objects(), register: '', logger: $logger)->acknowledge(recordId: 'm-1'));
	}//end testAFailedAcknowledgeIsNotAcknowledged()

	/**
	 * The records over the given object service.
	 *
	 * @param object|null          $objects  The object service, or null when OpenRegister is absent.
	 * @param string               $register The configured register.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return MentionQueueRecords
	 */
	private function records(?object $objects, string $register = 'dossiq', ?LoggerInterface $logger = null): MentionQueueRecords {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([['register', '', $register]]);

		return new MentionQueueRecords(settings: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end records()

	/**
	 * An object service that records its calls.
	 *
	 * @param mixed           $saved       What saveObject answers.
	 * @param array           $rows        What a search answers.
	 * @param mixed           $patched     What patchObject answers.
	 * @param \Throwable|null $searchFails What a search throws.
	 *
	 * @return object
	 */
	private function objects(mixed $saved = null, array $rows = [], mixed $patched = null, ?\Throwable $searchFails = null): object {
		$calls = &$this->calls;
		return new class($calls, $saved, $rows, $patched, $searchFails) {
			/**
			 * @param array           $calls       The call log.
			 * @param mixed           $saved       What saveObject answers.
			 * @param array           $rows        What a search answers.
			 * @param mixed           $patched     What patchObject answers.
			 * @param \Throwable|null $searchFails What a search throws.
			 */
			public function __construct(private array &$calls, private mixed $saved, private array $rows, private mixed $patched, private ?\Throwable $searchFails) {
			}

			/**
			 * @return mixed
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): mixed {
				$this->calls[] = ['saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				return $this->saved;
			}

			/**
			 * @return array
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['searchObjectsBySlug', ['register' => $register, 'schema' => $schema, 'filters' => $filters]];
				if ($this->searchFails !== null) {
					throw $this->searchFails;
				}

				return $this->rows;
			}

			/**
			 * @return mixed
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): mixed {
				$this->calls[] = ['patchObject', ['objectId' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema]];
				return $this->patched;
			}
		};
	}//end objects()
}//end class
