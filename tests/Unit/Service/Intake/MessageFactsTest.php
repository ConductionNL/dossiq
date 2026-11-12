<?php

/**
 * Message Facts Test
 *
 * Pins the facts a case opened from a channel message takes from it: the
 * title (mapped, else the first line of the text, else the untitled default),
 * the correspondent, and the received date and moment.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Intake\CollectionLinkQueue;
use OCA\Dossiq\Service\Intake\MessageFacts;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MessageFacts and CollectionLinkQueue.
 */
#[CoversClass(MessageFacts::class)]
#[CoversClass(CollectionLinkQueue::class)]
final class MessageFactsTest extends TestCase {
	/**
	 * Mock date normaliser: the clock and calendar boundary.
	 *
	 * @var CaseDateNormaliser&MockObject
	 */
	private CaseDateNormaliser&MockObject $dates;

	/**
	 * Subject under test.
	 *
	 * @var MessageFacts
	 */
	private MessageFacts $facts;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dates = $this->createMock(CaseDateNormaliser::class);
		$this->facts = new MessageFacts(dates: $this->dates);
	}//end setUp()

	/**
	 * Messages and payloads, and the title they give.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: string}>
	 */
	public static function titles(): array {
		return [
			'mapped title wins'         => [['text' => "Line one\nLine two"], ['title' => '  Aanvraag vergunning  '], 'Aanvraag vergunning'],
			'numeric mapped title'      => [['text' => 'x'], ['title' => 2026], '2026'],
			'blank mapped title'        => [['text' => "Eerste regel\r\nTweede"], ['title' => '   '], 'Eerste regel'],
			'array mapped title'        => [['text' => 'Tekst'], ['title' => ['x']], 'Tekst'],
			'first line of the text'    => [['text' => "  Klacht over geluid  \nDetails"], [], 'Klacht over geluid'],
			'no text'                   => [[], [], ChannelIntake::UNTITLED],
			'blank text'                => [['text' => "  \n  "], [], ChannelIntake::UNTITLED],
			'leading blank line'        => [['text' => "\nTweede regel"], [], 'Tweede regel'],
		];
	}//end titles()

	/**
	 * The title is the mapped title, else the first line of the text, else the untitled default.
	 *
	 * @param array<string,mixed> $message  The message.
	 * @param array<string,mixed> $payload  The mapped payload.
	 * @param string              $expected The title.
	 *
	 * @return void
	 */
	#[DataProvider('titles')]
	public function testTitle(array $message, array $payload, string $expected): void {
		self::assertSame(expected: $expected, actual: $this->facts->titleFor(message: $message, payload: $payload));
	}//end testTitle()

	/**
	 * A mapped title is cut at 255 characters, a first line at 120, counting multibyte characters.
	 *
	 * @return void
	 */
	public function testTitlesAreCut(): void {
		$mapped = $this->facts->titleFor(message: [], payload: ['title' => str_repeat('é', 300)]);
		$line   = $this->facts->titleFor(message: ['text' => str_repeat('ë', 200) . "\nrest"], payload: []);

		self::assertSame(expected: str_repeat('é', 255), actual: $mapped);
		self::assertSame(expected: str_repeat('ë', 120), actual: $line);
	}//end testTitlesAreCut()

	/**
	 * Messages and the correspondent they name.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function correspondents(): array {
		return [
			'scalar'               => [['correspondent' => '  jan@example.nl '], 'jan@example.nl'],
			'address first'        => [['correspondent' => ['name' => 'Jan', 'id' => '7', 'handle' => '@jan', 'address' => 'jan@example.nl']], 'jan@example.nl'],
			'then handle'          => [['correspondent' => ['name' => 'Jan', 'id' => '7', 'handle' => '@jan', 'address' => ' ']], '@jan'],
			'then id'              => [['correspondent' => ['name' => 'Jan', 'id' => 7]], '7'],
			'then name'            => [['correspondent' => ['name' => ' Jan ', 'address' => ['x']]], 'Jan'],
			'nothing usable'       => [['correspondent' => ['other' => 'x']], ''],
			'absent'               => [[], ''],
		];
	}//end correspondents()

	/**
	 * The correspondent is the scalar given, else the first usable of address, handle, id and name.
	 *
	 * @param array<string,mixed> $message  The message.
	 * @param string              $expected The correspondent.
	 *
	 * @return void
	 */
	#[DataProvider('correspondents')]
	public function testCorrespondent(array $message, string $expected): void {
		self::assertSame(expected: $expected, actual: $this->facts->correspondent(message: $message));
	}//end testCorrespondent()

	/**
	 * The received date is the message's own calendar date.
	 *
	 * @return void
	 */
	public function testReceivedDateIsTheMessagesDate(): void {
		$this->dates->expects(self::once())->method('toCalendarDateOrNull')->with('2026-03-04T10:00:00+01:00')->willReturn('2026-03-04');
		$this->dates->expects(self::never())->method('today');

		self::assertSame(expected: '2026-03-04', actual: $this->facts->receivedDate(message: ['receivedAt' => '2026-03-04T10:00:00+01:00']));
	}//end testReceivedDateIsTheMessagesDate()

	/**
	 * Without a readable received moment the received date is today.
	 *
	 * @return void
	 */
	public function testReceivedDateDefaultsToToday(): void {
		$this->dates->method('toCalendarDateOrNull')->with(null)->willReturn(null);
		$this->dates->method('today')->willReturn(new DateTimeImmutable('2026-11-12 00:00:00'));

		self::assertSame(expected: '2026-11-12', actual: $this->facts->receivedDate(message: []));
	}//end testReceivedDateDefaultsToToday()

	/**
	 * A readable received moment is written as the receivedAt field, formatted by the normaliser.
	 *
	 * @return void
	 */
	public function testReceivedAtFieldIsFormatted(): void {
		$moment = new DateTimeImmutable('2026-03-04T10:00:00+01:00');
		$this->dates->method('tryParse')->with('4 maart')->willReturn($moment);
		$this->dates->expects(self::once())->method('formatMoment')->with($moment)->willReturn('2026-03-04T10:00:00+01:00');

		self::assertSame(expected: ['receivedAt' => '2026-03-04T10:00:00+01:00'], actual: $this->facts->receivedAtField(message: ['receivedAt' => '4 maart']));
	}//end testReceivedAtFieldIsFormatted()

	/**
	 * Without a readable received moment no receivedAt field is written.
	 *
	 * @return void
	 */
	public function testNoReceivedAtFieldWithoutAMoment(): void {
		$this->dates->method('tryParse')->willReturn(null);
		$this->dates->expects(self::never())->method('formatMoment');

		self::assertSame(expected: [], actual: $this->facts->receivedAtField(message: ['receivedAt' => 'not a date']));
	}//end testNoReceivedAtFieldWithoutAMoment()

	/**
	 * Queuing a collection link adds one link job carrying the register, case, collection and subject.
	 *
	 * @return void
	 */
	public function testCollectionLinkIsQueuedAsOneJob(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())
			->method('add')
			->with(
				'OCA\Dossiq\BackgroundJob\LinkCollectionToCaseJob',
				['register' => 'dossiq', 'caseId' => 'case-1', 'collectionId' => 'col-9', 'subjectRef' => 'bsn:123']
			);

		(new CollectionLinkQueue(jobList: $jobs))->queue(register: 'dossiq', caseId: 'case-1', collectionId: 'col-9', subjectRef: 'bsn:123');
	}//end testCollectionLinkIsQueuedAsOneJob()
}//end class
