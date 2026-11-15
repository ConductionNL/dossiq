<?php

/**
 * Tests for ZtcEnrichmentTarget: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcEnrichmentTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for ZtcEnrichmentTarget.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZtcEnrichmentTargetTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$objectService = new stdClass();
		$subject = new ZtcEnrichmentTarget(
			objectService: $objectService,
			uuid: 'uuid-value',
			baseUrl: 'baseUrl-value'
		);

		self::assertSame(expected: $objectService, actual: $subject->objectService);
		self::assertSame(expected: 'uuid-value', actual: $subject->uuid);
		self::assertSame(expected: 'baseUrl-value', actual: $subject->baseUrl);
	}//end testPropertiesReadBack()
}//end class
