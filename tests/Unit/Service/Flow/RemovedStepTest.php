<?php

/**
 * Tests for RemovedStep: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RemovedStep;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RemovedStep.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
class RemovedStepTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new RemovedStep(
			stepId: 'stepId-value',
			successors: ['successors' => 1]
		);

		self::assertSame(expected: 'stepId-value', actual: $subject->stepId);
		self::assertSame(expected: ['successors' => 1], actual: $subject->successors);
	}//end testPropertiesReadBack()
}//end class
