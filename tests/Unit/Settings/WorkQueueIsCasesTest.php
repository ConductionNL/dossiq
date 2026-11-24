<?php

/**
 * Dossiq Work Queue Is Cases Test
 *
 * The work queue consists of cases. In OpenRegister's Mail panel the case
 * schema offers a Work queue block over the unassigned cases, and putting a
 * mail in it creates an unassigned case. The intake log is not offered there,
 * so the panel never creates a separate intake object.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Mail panel's work queue is a block over cases.
 */
final class WorkQueueIsCasesTest extends TestCase {

	/**
	 * A register.d fragment, decoded.
	 *
	 * @param string $name The fragment's file name.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(string $name): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $name;

		return json_decode((string)file_get_contents($path), true);
	}//end fragment()

	/**
	 * The case schema offers Case and a Work queue of unassigned cases that creates an unassigned case.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaOffersAWorkQueueOfUnassignedCases(): void {
		$blocks = $this->fragment(name: '36-case-mail-blocks.json')['components']['schemas']['case']['configuration']['mailBlocks'];
		$byKey  = array_column($blocks, null, 'key');

		$this->assertSame(expected: ['case', 'queue'], actual: array_keys($byKey));
		$this->assertSame(expected: ['assignee' => 'IS NULL'], actual: $byKey['queue']['filter']);
		$this->assertSame(expected: '', actual: $byKey['queue']['template']['assignee']);
		$this->assertFalse(condition: $byKey['queue']['appDialog']);
	}//end testTheCaseSchemaOffersAWorkQueueOfUnassignedCases()

	/**
	 * The intake log is not offered in the Mail panel.
	 *
	 * @return void
	 */
	public function testTheIntakeLogIsNotOfferedInTheMailPanel(): void {
		$intake = $this->fragment(name: '36-mail-intake.json')['components']['schemas']['mailIntakeEntry'];

		$this->assertNotContains(needle: 'mail', haystack: ($intake['configuration']['linkedTypes'] ?? []));
		$this->assertArrayNotHasKey(key: 'mailObjectTemplate', array: $intake['configuration']);
	}//end testTheIntakeLogIsNotOfferedInTheMailPanel()
}//end class
