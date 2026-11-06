<?php

/**
 * The Woo request's statuses after its decision do not send the case back to handling.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The role of every shipped Woo status, read from the register fragment the import loads.
 *
 * The case page's what-now card follows `statusRole`. Rehearsal R3: at
 * Publicatie, after the decision, the card read "Wat nu? Behandelen ...
 * Daarna: het besluit", because Publicatie carried the role of the handling
 * steps.
 */
class WooStatusRolesTest extends TestCase {

	private const CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * Every status of the Woo case type, in order: name to role.
	 *
	 * @return array<string, string>
	 */
	private function roles(): array {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/81-woo-verzoek.json'), true);
		$statuses = array_filter(
			$fragment['components']['objects'],
			fn (mixed $row): bool => is_array($row) === true && ($row['caseType'] ?? '') === self::CASE_TYPE && isset($row['role'], $row['order']) === true
		);
		usort($statuses, fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		return array_column($statuses, 'role', 'name');
	}//end roles()

	/**
	 * After the decision, every status that is not final keeps the decision's role.
	 *
	 * @return void
	 */
	public function testTheStatusesAfterTheDecisionKeepItsRole(): void {
		$roles = $this->roles();
		self::assertSame('review', $roles['Besluit']);

		$after = array_slice($roles, (int)array_search('Besluit', array_keys($roles), true) + 1, null, true);
		self::assertNotSame([], $after);
		foreach ($after as $name => $role) {
			self::assertContains($role, ['review', 'closed'], $name . ' comes after the decision and reads as ' . $role);
		}
	}//end testTheStatusesAfterTheDecisionKeepItsRole()
}//end class
