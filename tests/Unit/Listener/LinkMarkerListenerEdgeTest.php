<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\LinkMarkerListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A marker rule that fails is skipped without blocking the others, and a
 * reference that is not a uuid or an event that is not a save writes nothing.
 *
 * @covers \OCA\Dossiq\Listener\LinkMarkerListener
 * @uses   \OCA\Dossiq\Listener\LinkMarkerRun
 * @uses   \OCA\Dossiq\Listener\Support\ReadsEventObjects
 * @uses   \OCA\Dossiq\Service\Relation\LinkMarkerRule
 * @uses   \OCA\Dossiq\Service\Relation\LinkMarkerRuleReader
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class LinkMarkerListenerEdgeTest extends TestCase {

	/**
	 * @var InMemoryRegister The register.
	 */
	private InMemoryRegister $store;

	/**
	 * @var list<array{0: string, 1: array<string, mixed>}> The warnings logged.
	 */
	private array $warnings = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->warnings = [];
		$this->store->seed(schema: 'record', uuid: 'rec-1', row: ['status' => 'closed', 'followUpOpen' => false]);
		$this->store->seed(schema: 'file', uuid: 'file-1', row: ['status' => 'closed', 'appealed' => false]);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testARuleWhoseWriteFailsIsSkippedAndTheNextStillRuns(): void {
		$this->store->refusedSchemas = ['record'];

		$this->listener()->handle(
			$this->saved(['id' => 'ap-1', 'sourceRecord' => 'rec-1', 'sourceFile' => 'file-1', '@self' => ['schema' => 'appeal']])
		);

		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith('Dossiq: link marker rule skipped: ', $this->warnings[0][0]);
		$this->assertSame(['marker' => 'followUpOpen'], $this->warnings[0][1]);
		$this->assertFalse($this->store->row(schema: 'record', uuid: 'rec-1')['followUpOpen']);
		$this->assertTrue($this->store->row(schema: 'file', uuid: 'file-1')['appealed']);
	}//end testARuleWhoseWriteFailsIsSkippedAndTheNextStillRuns()

	/**
	 * @return void
	 */
	public function testAReferenceThatIsNotAStringWritesNothing(): void {
		$this->listener()->handle(
			$this->saved(['id' => 'ap-1', 'sourceRecord' => ['rec-1'], 'sourceFile' => 42, '@self' => ['schema' => 'appeal']])
		);

		$this->assertSame(0, $this->store->writes);
		$this->assertSame([], $this->warnings);
	}//end testAReferenceThatIsNotAStringWritesNothing()

	/**
	 * @return void
	 */
	public function testASourceWhoseSelfIsNotAnObjectIsPlacedByItsTopLevelSchema(): void {
		$this->listener()->handle(
			$this->saved(['id' => 'ap-1', 'sourceRecord' => 'rec-1', '@self' => 'appeal', 'schema' => 'appeal'])
		);

		$this->assertTrue($this->store->row(schema: 'record', uuid: 'rec-1')['followUpOpen']);
	}//end testASourceWhoseSelfIsNotAnObjectIsPlacedByItsTopLevelSchema()

	/**
	 * @return void
	 */
	public function testAnEventThatIsNotASaveWritesNothing(): void {
		$this->listener()->handle(new Event());

		$this->assertSame(0, $this->store->writes);
	}//end testAnEventThatIsNotASaveWritesNothing()

	/**
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function saved(array $object): ObjectUpdatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($object);

		return new ObjectUpdatedEvent($entity);
	}//end saved()

	/**
	 * @return LinkMarkerListener The listener with two rules: appeal → record, appeal → file.
	 */
	private function listener(): LinkMarkerListener {
		$rule = static fn (string $field, string $target, string $marker): array => [
			'source' => 'appeal_schema',
			'field'  => $field,
			'target' => $target,
			'marker' => $marker,
			'when'   => [['on' => 'target', 'field' => 'status', 'in' => ['closed']]],
		];
		$rules = (string)json_encode([$rule('sourceRecord', 'record_schema', 'followUpOpen'), $rule('sourceFile', 'file_schema', 'appealed')]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register'                    => 'dossiq',
				'appeal_schema'               => 'appeal',
				'record_schema'               => 'record',
				'file_schema'                 => 'file',
				LinkMarkerListener::RULES_KEY => $rules,
				default                       => '',
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new LinkMarkerListener(settingsService: $settings, logger: $logger);
	}//end listener()
}//end class
