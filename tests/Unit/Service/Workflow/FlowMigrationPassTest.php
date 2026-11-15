<?php

/**
 * Tests for FlowMigrationPass: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
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
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\FlowMigrationPass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for FlowMigrationPass.
 *
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */
class FlowMigrationPassTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$flowService = new stdClass();
		$subject = new FlowMigrationPass(
			flowService: $flowService,
			existing: ['existing' => 1],
			statusNames: ['statusNames' => 1],
			dryRun: true
		);

		self::assertSame(expected: $flowService, actual: $subject->flowService);
		self::assertSame(expected: ['existing' => 1], actual: $subject->existing);
		self::assertSame(expected: ['statusNames' => 1], actual: $subject->statusNames);
		self::assertSame(expected: true, actual: $subject->dryRun);
	}//end testPropertiesReadBack()
}//end class
