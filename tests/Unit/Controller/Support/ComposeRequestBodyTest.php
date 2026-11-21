<?php

/**
 * ComposeRequestBody unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ComposeRequestBody;
use PHPUnit\Framework\TestCase;

/**
 * What a compose request names: its template and the fields it sets.
 *
 * @covers \OCA\Dossiq\Controller\Support\ComposeRequestBody
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class ComposeRequestBodyTest extends TestCase {

	/**
	 * A named template is read as a string; none is null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheTemplateIsReadAsAStringOrNull(): void {
		$this->assertSame(expected: '42', actual: (new ComposeRequestBody(body: ['templateId' => 42]))->templateId());
		$this->assertNull(actual: (new ComposeRequestBody(body: ['caseId' => 'case-1']))->templateId());
		$this->assertNull(actual: (new ComposeRequestBody(body: ['templateId' => null]))->templateId());
	}//end testTheTemplateIsReadAsAStringOrNull()

	/**
	 * Only the addressee and the three decision fields are set; an empty addressee is left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testOnlyTheAddresseeAndTheDecisionFieldsAreSet(): void {
		$body = new ComposeRequestBody(
			body: [
				'caseId' => 'case-1',
				'templateId' => 'tpl-1',
				'addressee' => ['name' => 'J. Jansen'],
				'decisionType' => 'toekenning',
				'rationale' => 'Voldoet',
				'decision' => 'Toegekend',
				'status' => 'signed',
			]
		);

		$this->assertSame(
			expected: [
				'addressee' => ['name' => 'J. Jansen'],
				'decisionType' => 'toekenning',
				'rationale' => 'Voldoet',
				'decision' => 'Toegekend',
			],
			actual: $body->fields()
		);
		$this->assertSame(
			expected: ['rationale' => 'Voldoet'],
			actual: (new ComposeRequestBody(body: ['addressee' => [], 'rationale' => 'Voldoet']))->fields()
		);
	}//end testOnlyTheAddresseeAndTheDecisionFieldsAreSet()
}//end class
