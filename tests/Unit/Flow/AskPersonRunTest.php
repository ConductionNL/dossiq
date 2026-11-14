<?php

/**
 * AskPersonRun unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Flow
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

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\AskPersonRun;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AskPersonRun.
 *
 * @covers \OCA\Dossiq\Flow\AskPersonRun
 */
class AskPersonRunTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$resume = (new FlowResumeState([]))->forNode('ask-1');
		$value = new AskPersonRun(
			items: [['json' => ['caseId' => 'case-1']]],
			config: ['question' => 'Akkoord?'],
			context: ['run' => 'run-1'],
			resume: $resume
		);

		$this->assertSame(expected: [['json' => ['caseId' => 'case-1']]], actual: $value->items);
		$this->assertSame(expected: ['question' => 'Akkoord?'], actual: $value->config);
		$this->assertSame(expected: ['run' => 'run-1'], actual: $value->context);
		$this->assertSame(expected: $resume, actual: $value->resume);
	}//end testPropertiesReadBack()
}//end class
