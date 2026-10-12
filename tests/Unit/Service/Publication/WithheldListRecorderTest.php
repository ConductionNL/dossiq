<?php

/**
 * Unit tests for WithheldListRecorder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The recorder hands the list over and never lets a failure undo a publish.
 */
class WithheldListRecorderTest extends TestCase {

	use EnglishL10n;

	/**
	 * A recorder whose container answers the fake door.
	 *
	 * @param FakeWithheldDocuments $door        The fake door.
	 * @param string                $recordClass The class it looks for.
	 *
	 * @return WithheldListRecorder
	 */
	private function recorder(FakeWithheldDocuments $door, string $recordClass = FakeWithheldDocuments::class): WithheldListRecorder {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($door);

		return new WithheldListRecorder(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $this->english(),
			recordClass: $recordClass,
		);
	}//end recorder()

	/**
	 * The list goes over once, with the source, and the answer comes back.
	 *
	 * @return void
	 */
	public function testTheListIsRecordedUnderTheSource(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = ['recorded' => 1, 'refused' => [['position' => 7, 'code' => '9.9', 'reason' => 'unknown-ground']]];
		$entries = [['position' => 3, 'grounds' => ['5.1.2.e']], ['position' => 7, 'grounds' => ['9.9']]];

		$answer = $this->recorder($door)->record('pub-1', $entries);

		self::assertSame([['pub-1', $entries, 'dossiq']], $door->calls);
		self::assertSame(
			['status' => 'recorded', 'recorded' => 1, 'refused' => [['position' => 7, 'code' => '9.9', 'reason' => 'unknown-ground']]],
			$answer
		);
	}//end testTheListIsRecordedUnderTheSource()

	/**
	 * Without the door nothing is called and the answer says why.
	 *
	 * @return void
	 */
	public function testWithoutTheDoorNothingIsRecorded(): void {
		$door = new FakeWithheldDocuments();

		$answer = $this->recorder($door, 'OCA\OpenCatalogi\Service\Woo\NoSuchClass')->record('pub-1', []);

		self::assertSame([], $door->calls);
		self::assertSame(['status' => 'not-recorded', 'reason' => 'opencatalogi-too-old'], $answer);
	}//end testWithoutTheDoorNothingIsRecorded()

	/**
	 * A throwing door is an answer, not an exception.
	 *
	 * @return void
	 */
	public function testAThrowingDoorIsReportedNotRaised(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = null;

		self::assertSame(
			['status' => 'not-recorded', 'reason' => 'record-failed'],
			$this->recorder($door)->record('pub-1', [['position' => 1, 'grounds' => ['5.1.1.c']]])
		);
	}//end testAThrowingDoorIsReportedNotRaised()

	/**
	 * A door that exists and will not resolve is a failure, not an old opencatalogi.
	 *
	 * @return void
	 */
	public function testAnUnresolvableDoorIsReportedAsAFailure(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no service'));
		$recorder = new WithheldListRecorder(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $this->english(),
			recordClass: FakeWithheldDocuments::class,
		);

		self::assertSame(['status' => 'not-recorded', 'reason' => 'record-failed'], $recorder->record('pub-1', []));
	}//end testAnUnresolvableDoorIsReportedAsAFailure()
	/**
	 * A refused entry is named in one sentence with its number, code and reason.
	 *
	 * @return void
	 */
	public function testARefusedWithheldRecordIsShown(): void {
		$sentence = $this->recorder(new FakeWithheldDocuments())->describe(
			['status' => 'recorded', 'recorded' => 1, 'refused' => [['position' => 7, 'code' => '5.1.2.e', 'reason' => 'unknown-ground']]]
		);

		self::assertSame('Opencatalogi did not record these withheld documents: number 7 (5.1.2.e, unknown-ground).', $sentence);
	}//end testARefusedWithheldRecordIsShown()

	/**
	 * Not recorded says why, and that the publication stands.
	 *
	 * @return void
	 */
	public function testNotRecordedSaysWhy(): void {
		$recorder = $this->recorder(new FakeWithheldDocuments());

		self::assertStringContainsString('cannot record them yet', $recorder->describe(['status' => 'not-recorded', 'reason' => 'opencatalogi-too-old']));
		self::assertStringContainsString('gave an error', $recorder->describe(['status' => 'not-recorded', 'reason' => 'record-failed']));
		self::assertSame('', $recorder->describe(['status' => 'recorded', 'recorded' => 2, 'refused' => []]));
	}//end testNotRecordedSaysWhy()
}//end class
