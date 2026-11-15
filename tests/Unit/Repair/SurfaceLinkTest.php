<?php

/**
 * Unit test for SurfaceLink.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SurfaceLink;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Repair\SurfaceLink
 */
class SurfaceLinkTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$output = $this->createMock(originalClassName: \OCP\Migration\IOutput::class);
		$schemaSlug = 'schemaSlug-value';
		$raise = static fn (array $obj): string => 'ref';

		$value = new SurfaceLink(
			objectService: $objectService,
			output: $output,
			schemaSlug: $schemaSlug,
			raise: $raise,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $output, actual: $value->output);
		$this->assertSame(expected: $schemaSlug, actual: $value->schemaSlug);
		$this->assertSame(expected: $raise, actual: $value->raise);
	}//end testEveryPropertyReadsBack()
}//end class
