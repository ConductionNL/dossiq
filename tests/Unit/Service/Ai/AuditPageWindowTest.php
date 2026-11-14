<?php

/**
 * Tests for AuditPageWindow: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
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
 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AuditPageWindow;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditPageWindow.
 *
 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
 */
class AuditPageWindowTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new AuditPageWindow(
			limit: 7,
			offset: 7
		);

		self::assertSame(expected: 7, actual: $subject->limit);
		self::assertSame(expected: 7, actual: $subject->offset);
	}//end testPropertiesReadBack()
}//end class
