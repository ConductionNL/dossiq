<?php

/**
 * Unit test for ConnectedDocumentUpload.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\ConnectedDocumentUpload;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Cases\ConnectedDocumentUpload
 */
class ConnectedDocumentUploadTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$fileName = 'fileName-value';
		$content = 'content-value';
		$format = 'format-value';
		$metadata = ['metadata' => 4];

		$value = new ConnectedDocumentUpload(
			caseId: $caseId,
			fileName: $fileName,
			content: $content,
			format: $format,
			metadata: $metadata,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $fileName, actual: $value->fileName);
		$this->assertSame(expected: $content, actual: $value->content);
		$this->assertSame(expected: $format, actual: $value->format);
		$this->assertSame(expected: $metadata, actual: $value->metadata);
	}//end testEveryPropertyReadsBack()
}//end class
