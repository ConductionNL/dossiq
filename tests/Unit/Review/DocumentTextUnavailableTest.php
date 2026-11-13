<?php

/**
 * A document's extracted text when OpenRegister's extraction is missing, broken or refuses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Review\DocumentText;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Review\DocumentText
 */
class DocumentTextUnavailableTest extends TestCase {

	private const EXTRACTION = 'OCA\OpenRegister\Service\TextExtractionService';

	/**
	 * A container that throws while resolving the extraction is logged at info, and no text is read.
	 *
	 * @return void
	 */
	public function testABrokenContainerIsLoggedAndReadsNoText(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(self::EXTRACTION)->willReturn(true);
		$container->method('get')->with(self::EXTRACTION)->willThrowException(new RuntimeException('not wired'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'Dossiq: OpenRegister\'s text extraction is not available, so no text condition matches',
			['app' => 'dossiq', 'exception' => 'not wired']
		);

		$this->assertNull((new DocumentText(container: $container, logger: $logger))->extracted(document: ['fileId' => '12']));
	}//end testABrokenContainerIsLoggedAndReadsNoText()

	/**
	 * A missing extraction, or one without getExtractedText, reads no text and logs nothing.
	 *
	 * @return void
	 */
	public function testAMissingOrForeignExtractionReadsNoText(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		$absent = $this->createMock(ContainerInterface::class);
		$absent->method('has')->willReturn(false);
		$absent->expects($this->never())->method('get');
		$this->assertNull((new DocumentText(container: $absent, logger: $logger))->extracted(document: ['fileId' => 12]));

		$foreign = $this->createMock(ContainerInterface::class);
		$foreign->method('has')->willReturn(true);
		$foreign->method('get')->willReturn(new \stdClass());
		$this->assertNull((new DocumentText(container: $foreign, logger: $logger))->extracted(document: ['fileId' => 12]));
	}//end testAMissingOrForeignExtractionReadsNoText()

	/**
	 * Without a positive numeric file id the container is not even asked.
	 *
	 * @return void
	 */
	public function testWithoutAFileIdTheContainerIsNotAsked(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('has');
		$text = new DocumentText(container: $container, logger: $this->createMock(LoggerInterface::class));

		foreach ([[], ['fileId' => null], ['fileId' => 'abc'], ['fileId' => 0], ['fileId' => -3]] as $document) {
			$this->assertNull($text->extracted(document: $document), json_encode($document));
		}
	}//end testWithoutAFileIdTheContainerIsNotAsked()

	/**
	 * The text is read by its integer file id; blank text reads as none.
	 *
	 * @return void
	 */
	public function testTheTextIsReadByFileIdAndBlankIsNone(): void {
		$extraction = new class {
			public array $asked = [];
			public mixed $text = 'Besluit op bezwaar';

			public function getExtractedText(int $fileId): mixed {
				$this->asked[] = $fileId;
				return $this->text;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($extraction);
		$text = new DocumentText(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame('Besluit op bezwaar', $text->extracted(document: ['fileId' => '42']));
		$extraction->text = " \n ";
		$this->assertNull($text->extracted(document: ['fileId' => 42]));
		$extraction->text = null;
		$this->assertNull($text->extracted(document: ['fileId' => 42]));
		$this->assertSame([42, 42, 42], $extraction->asked);
	}//end testTheTextIsReadByFileIdAndBlankIsNone()
}//end class
