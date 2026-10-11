<?php

/**
 * Tests for DocumentText: the text OpenRegister already extracted from a document's file.
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
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class DocumentTextTest extends TestCase {

	/**
	 * The service over a container that holds the given extraction service, or none.
	 *
	 * @param object|null $extraction The extraction double.
	 *
	 * @return DocumentText
	 */
	private function text(?object $extraction): DocumentText {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($extraction !== null);
		$container->method('get')->willReturn($extraction);

		return new DocumentText(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end text()

	/**
	 * The extracted text of the document's file is read by its file id.
	 *
	 * @return void
	 */
	public function testTheExtractedTextIsReadByFileId(): void {
		$extraction = new class {
			/**
			 * @var list<int>
			 */
			public array $asked = [];

			/**
			 * @param int $fileId The file.
			 *
			 * @return string|null
			 */
			public function getExtractedText(int $fileId): ?string {
				$this->asked[] = $fileId;
				return $fileId === 7 ? 'Overleg over het fietspad' : null;
			}
		};

		$this->assertSame('Overleg over het fietspad', $this->text(extraction: $extraction)->of(document: ['fileId' => '7']));
		$this->assertNull($this->text(extraction: $extraction)->of(document: ['fileId' => 8]), 'no extracted text is no text');
		$this->assertSame([7, 8], $extraction->asked);
	}//end testTheExtractedTextIsReadByFileId()

	/**
	 * No file, no extraction service, an old service or a failing read all answer no text.
	 *
	 * @return void
	 */
	public function testWithoutAFileOrAnExtractionThereIsNoText(): void {
		$failing = new class {
			/**
			 * @param int $fileId The file.
			 *
			 * @return string|null
			 */
			public function getExtractedText(int $fileId): ?string {
				throw new RuntimeException('chunks table missing for '.$fileId);
			}
		};

		$this->assertNull($this->text(extraction: $failing)->of(document: []));
		$this->assertNull($this->text(extraction: null)->of(document: ['fileId' => 7]));
		$this->assertNull($this->text(extraction: new \stdClass())->of(document: ['fileId' => 7]));
		$this->assertNull($this->text(extraction: $failing)->of(document: ['fileId' => 7]));
	}//end testWithoutAFileOrAnExtractionThereIsNoText()
}//end class
