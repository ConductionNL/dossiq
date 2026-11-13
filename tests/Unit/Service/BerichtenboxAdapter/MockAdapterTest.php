<?php

/**
 * Unit tests for the mock digital post adapter.
 *
 * The mock answers every letter as sent, so what it may NOT do matters most:
 * it must not log a full BSN, and its answer must carry a fresh mock id and
 * a parseable send time so a caller that stores the answer stores something
 * recognisably simulated.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\BerichtenboxAdapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\BerichtenboxAdapter;

use DateTimeImmutable;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the mock answers and what it logs.
 *
 * @covers \OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter
 */
class MockAdapterTest extends TestCase {

	/**
	 * A send answers `sent` with a mock id and a send time of now.
	 *
	 * @return void
	 */
	public function testASendAnswersSentWithAMockIdAndTheSendTime(): void {
		$adapter = new MockAdapter($this->createMock(LoggerInterface::class));
		$before = new DateTimeImmutable('-1 second');

		$result = $adapter->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');

		$this->assertInstanceOf(BerichtenboxAdapterInterface::class, $adapter);
		$this->assertSame(['messageId', 'status', 'sentAt'], array_keys($result));
		$this->assertSame('sent', $result['status']);
		$this->assertMatchesRegularExpression('/^mock-[0-9a-f]{16}$/', $result['messageId']);
		$sentAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $result['sentAt']);
		$this->assertNotFalse($sentAt, 'sentAt must be an ISO 8601 timestamp');
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $sentAt->getTimestamp());
		$this->assertLessThanOrEqual((new DateTimeImmutable('+1 second'))->getTimestamp(), $sentAt->getTimestamp());
	}//end testASendAnswersSentWithAMockIdAndTheSendTime()

	/**
	 * Every send gets its own id.
	 *
	 * @return void
	 */
	public function testEverySendGetsItsOwnId(): void {
		$adapter = new MockAdapter($this->createMock(LoggerInterface::class));

		$first = $adapter->sendMessage('123456782', 'A', 'a', 'X')['messageId'];
		$second = $adapter->sendMessage('123456782', 'A', 'a', 'X')['messageId'];

		$this->assertNotSame($first, $second);
	}//end testEverySendGetsItsOwnId()

	/**
	 * The log names the letter and the id it answered, with the BSN masked.
	 *
	 * @return void
	 */
	public function testTheLogMasksTheBsnAndNamesTheLetter(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->willReturnCallback(
				static function (string $message, array $context) use (&$logged): void {
					$logged = ['message' => $message, 'context' => $context];
				}
			);

		$result = (new MockAdapter($logger))->sendMessage(
			bsn: '123456782',
			subject: 'Besluit',
			body: 'De tekst',
			typeCode: 'BESLUIT',
			attachment: 'files/besluit.pdf',
		);

		$this->assertSame('MockBerichtenbox: Message sent', $logged['message']);
		$this->assertSame(
			[
				'messageId' => $result['messageId'],
				'bsn' => '1234*****',
				'subject' => 'Besluit',
				'typeCode' => 'BESLUIT',
				'hasAttachment' => true,
			],
			$logged['context']
		);
		$this->assertStringNotContainsString('123456782', json_encode($logged['context']));
	}//end testTheLogMasksTheBsnAndNamesTheLetter()

	/**
	 * A letter without an attachment is logged as such.
	 *
	 * @return void
	 */
	public function testALetterWithoutAnAttachmentIsLoggedAsSuch(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('MockBerichtenbox: Message sent', $this->callback(
				static fn (array $context): bool => $context['hasAttachment'] === false
			));

		(new MockAdapter($logger))->sendMessage('123456782', 'Besluit', 'De tekst', 'BESLUIT');
	}//end testALetterWithoutAnAttachmentIsLoggedAsSuch()
}//end class
