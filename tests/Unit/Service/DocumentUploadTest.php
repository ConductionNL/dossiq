<?php

/**
 * Unit test for DocumentUpload.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DocumentUpload;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\DocumentUpload
 */
class DocumentUploadTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$fileName = 'fileName-value';
		$content = 'content-value';
		$metadata = ['metadata' => 3];

		$value = new DocumentUpload(
			caseId: $caseId,
			fileName: $fileName,
			content: $content,
			metadata: $metadata,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $fileName, actual: $value->fileName);
		$this->assertSame(expected: $content, actual: $value->content);
		$this->assertSame(expected: $metadata, actual: $value->metadata);
	}//end testEveryPropertyReadsBack()
}//end class
