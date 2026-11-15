<?php

/**
 * Tests for TargetNote: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Complaint
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use OCA\Dossiq\Service\Complaint\TargetNote;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TargetNote.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class TargetNoteTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new TargetNote(
			note: 'note-value',
			onTarget: true,
			over: true
		);

		self::assertSame(expected: 'note-value', actual: $subject->note);
		self::assertSame(expected: true, actual: $subject->onTarget);
		self::assertSame(expected: true, actual: $subject->over);
	}//end testPropertiesReadBack()
}//end class
