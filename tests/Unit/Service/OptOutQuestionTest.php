<?php

/**
 * OptOutQuestion unit tests.
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

use OCA\Dossiq\Service\OptOutQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OptOutQuestion.
 *
 * @covers \OCA\Dossiq\Service\OptOutQuestion
 */
class OptOutQuestionTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new OptOutQuestion(
			recipient: 'a@example.test',
			category: 'besluit',
			caseRef: 'case-1'
		);

		$this->assertSame(expected: 'a@example.test', actual: $value->recipient);
		$this->assertSame(expected: 'besluit', actual: $value->category);
		$this->assertSame(expected: 'case-1', actual: $value->caseRef);
	}//end testPropertiesReadBack()
}//end class
