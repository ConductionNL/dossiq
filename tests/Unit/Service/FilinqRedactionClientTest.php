<?php

/**
 * The redaction hand-off to filinq: detect, mark checked, anonymise.
 *
 * WHAT THIS GUARDS. The hand-off used to detect and then anonymise with no
 * review mark in between, and filinq refuses every run nobody checked, so no
 * copy was ever written. These cases run the client against a filinq that
 * applies filinq's own review gate (tests/Support/FakeFilinq.php), so the
 * control (no mark, refused) and the case (mark, copy) differ only in the call
 * this client must make.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FilinqRedactionClient;
use OCA\Dossiq\Tests\Support\FakeFilinq;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\FilinqRedactionClient
 */
class FilinqRedactionClientTest extends TestCase {

	/**
	 * The entity list the cases redact.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const ENTITIES = [
		['type' => 'PERSON', 'value' => 'Sanne de Vries', 'source' => 'filinq'],
		['type' => 'PHONE', 'value' => '06 1234 5678', 'source' => 'filinq'],
	];

	/**
	 * The filinq the client talks to.
	 *
	 * @var FakeFilinq
	 */
	private FakeFilinq $filinq;

	/**
	 * A fresh filinq per case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->filinq = new FakeFilinq();
		$this->filinq->entities = self::ENTITIES;
	}//end setUp()

	/**
	 * The client over the given container, or over the fake filinq.
	 *
	 * @param ContainerInterface|null $container The container.
	 *
	 * @return FilinqRedactionClient
	 */
	private function client(?ContainerInterface $container = null): FilinqRedactionClient {
		return new FilinqRedactionClient(container: ($container ?? $this->filinq->container()), logger: new NullLogger());
	}//end client()

	/**
	 * Detection answers filinq's entities.
	 *
	 * @return void
	 */
	public function testDetectAnswersFilinqsEntities(): void {
		self::assertSame(self::ENTITIES, $this->client()->detect(fileId: 42));
	}//end testDetectAnswersFilinqsEntities()

	/**
	 * CONTROL: without a review mark filinq refuses, and that is the outcome `not_checked`.
	 *
	 * @return void
	 */
	public function testWithoutAMarkFilinqRefusesAndNothingIsWritten(): void {
		$outcome = $this->client()->anonymize(fileId: 42, entities: self::ENTITIES, userId: 'pjansen');

		self::assertSame('not_checked', $outcome['status']);
		self::assertSame('never_checked', $outcome['notChecked']);
		self::assertNull($outcome['anonymizedFileId']);
		self::assertSame([], $this->filinq->anonymised);
	}//end testWithoutAMarkFilinqRefusesAndNothingIsWritten()

	/**
	 * With the officer's mark on the same list, filinq writes the copy.
	 *
	 * @return void
	 */
	public function testAMarkOnTheSameListLetsFilinqWriteTheCopy(): void {
		$client = $this->client();
		$client->markChecked(fileId: 42, entities: self::ENTITIES, checkedBy: 'pjansen');

		$outcome = $client->anonymize(fileId: 42, entities: array_reverse(self::ENTITIES), userId: 'pjansen');

		self::assertSame('redacted', $outcome['status']);
		self::assertSame(9001, $outcome['anonymizedFileId']);
		self::assertSame('regex', $outcome['detectionBackend']);
		self::assertSame('pjansen', $this->filinq->marks->marks[0]['checkedBy']);
		self::assertCount(1, $this->filinq->anonymised);
	}//end testAMarkOnTheSameListLetsFilinqWriteTheCopy()

	/**
	 * A mark on a different list does not cover this one.
	 *
	 * @return void
	 */
	public function testAMarkOnAnotherListDoesNotCoverThisOne(): void {
		$client = $this->client();
		$client->markChecked(fileId: 42, entities: [self::ENTITIES[0]], checkedBy: 'pjansen');

		$outcome = $client->anonymize(fileId: 42, entities: self::ENTITIES, userId: 'pjansen');

		self::assertSame('not_checked', $outcome['status']);
		self::assertSame('checked_an_older_run', $outcome['notChecked']);
	}//end testAMarkOnAnotherListDoesNotCoverThisOne()

	/**
	 * A check names a person.
	 *
	 * @return void
	 */
	public function testAMarkWithoutAPersonIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('checked_by_missing');

		$this->client()->markChecked(fileId: 42, entities: self::ENTITIES, checkedBy: ' ');
	}//end testAMarkWithoutAPersonIsRefused()

	/**
	 * A run with no live detector is its own outcome, with filinq's reason (dossiq#3191).
	 *
	 * @return void
	 */
	public function testNoLiveDetectorIsReportedWithFilinqsReason(): void {
		$this->filinq->detectorLive = false;
		$client = $this->client();
		$client->markChecked(fileId: 42, entities: self::ENTITIES, checkedBy: 'pjansen');

		$outcome = $client->anonymize(fileId: 42, entities: self::ENTITIES, userId: 'pjansen');

		self::assertSame('detection_unavailable', $outcome['status']);
		self::assertSame('detection_disabled', $outcome['detectionUnavailable']);
		self::assertNull($outcome['detectionBackend']);
		self::assertSame([], $this->filinq->anonymised);
	}//end testNoLiveDetectorIsReportedWithFilinqsReason()

	/**
	 * An empty list redacts nothing, so it is not called redacted.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsNotCalledRedacted(): void {
		$client = $this->client();
		$client->markChecked(fileId: 42, entities: [], checkedBy: 'pjansen');

		self::assertSame('no_entities_detected', $client->anonymize(fileId: 42, entities: [], userId: 'pjansen')['status']);
	}//end testAnEmptyListIsNotCalledRedacted()

	/**
	 * A run that names no output file is not called redacted.
	 *
	 * @return void
	 */
	public function testARunWithoutAnOutputFileIsNotCalledRedacted(): void {
		$this->filinq->outputFileId = null;
		$client = $this->client();
		$client->markChecked(fileId: 42, entities: self::ENTITIES, checkedBy: 'pjansen');

		self::assertSame('no_output_produced', $client->anonymize(fileId: 42, entities: self::ENTITIES, userId: 'pjansen')['status']);
	}//end testARunWithoutAnOutputFileIsNotCalledRedacted()

	/**
	 * Without filinq every call says so.
	 *
	 * @return void
	 */
	public function testWithoutFilinqEveryCallRefuses(): void {
		$empty = (new FakeFilinq())->container();
		$none = $this->createMock(ContainerInterface::class);
		$none->method('get')->willReturnCallback(static fn (string $id): mixed => $empty->get($id . '\Missing'));
		$client = $this->client(container: $none);

		foreach ([
			static fn () => $client->detect(fileId: 42),
			static fn () => $client->markChecked(fileId: 42, entities: self::ENTITIES, checkedBy: 'pjansen'),
			static fn () => $client->anonymize(fileId: 42, entities: self::ENTITIES, userId: 'pjansen'),
		] as $call) {
			try {
				$call();
				self::fail('A call without filinq must refuse.');
			} catch (RuntimeException $e) {
				self::assertStringStartsWith('filinq_unavailable', $e->getMessage());
			}
		}
	}//end testWithoutFilinqEveryCallRefuses()
}//end class
