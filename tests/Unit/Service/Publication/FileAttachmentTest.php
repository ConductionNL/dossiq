<?php

/**
 * FileAttachment unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Publication
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

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\FileAttachment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FileAttachment.
 *
 * @covers \OCA\Dossiq\Service\Publication\FileAttachment
 */
class FileAttachmentTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new FileAttachment(
			objectId: 'obj-1',
			fileName: 'besluit.pdf',
			base64Content: 'JVBERi0='
		);

		$this->assertSame(expected: 'obj-1', actual: $value->objectId);
		$this->assertSame(expected: 'besluit.pdf', actual: $value->fileName);
		$this->assertSame(expected: 'JVBERi0=', actual: $value->base64Content);
	}//end testPropertiesReadBack()
}//end class
