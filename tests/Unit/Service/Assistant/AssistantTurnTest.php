<?php

/**
 * Tests for AssistantTurn: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assistant
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assistant;

use OCA\Dossiq\Service\Assistant\AssistantTurn;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AssistantTurn.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
class AssistantTurnTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$subject = new AssistantTurn(
			userId: 'userId-value',
			caseId: 'caseId-value',
			message: 'message-value'
		);

		self::assertSame(expected: 'userId-value', actual: $subject->userId);
		self::assertSame(expected: 'caseId-value', actual: $subject->caseId);
		self::assertSame(expected: 'message-value', actual: $subject->message);
	}//end testPropertiesReadBack()
}//end class
