<?php

/**
 * Unit test for UploadedDocumentProfile.
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

use OCA\Dossiq\Service\UploadedDocumentProfile;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\UploadedDocumentProfile
 */
class UploadedDocumentProfileTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$type = 'type-value';
		$classification = 'classification-value';
		$direction = 'direction-value';
		$correspondents = ['correspondents' => 3];

		$value = new UploadedDocumentProfile(
			type: $type,
			classification: $classification,
			direction: $direction,
			correspondents: $correspondents,
		);

		$this->assertSame(expected: $type, actual: $value->type);
		$this->assertSame(expected: $classification, actual: $value->classification);
		$this->assertSame(expected: $direction, actual: $value->direction);
		$this->assertSame(expected: $correspondents, actual: $value->correspondents);
	}//end testEveryPropertyReadsBack()
}//end class
