<?php

/**
 * PublicationTarget unit tests.
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

use OCA\Dossiq\Service\Publication\PublicationTarget;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublicationTarget.
 *
 * @covers \OCA\Dossiq\Service\Publication\PublicationTarget
 */
class PublicationTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new PublicationTarget(
			register: 'publication',
			schema: 'document'
		);

		$this->assertSame(expected: 'publication', actual: $value->register);
		$this->assertSame(expected: 'document', actual: $value->schema);
	}//end testPropertiesReadBack()
}//end class
