<?php

/**
 * TransitionSpecReader Unit Tests
 *
 * Verifies the reader's handling of malformed transition data: a non-list
 * `guards` value, non-array guard and action entries, the legacy `actions`
 * spelling, and the silent role-guard detection the offer relies on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 */
class TransitionSpecReaderTest extends TestCase {

	/**
	 * A `guards` value that is not a list is read as no guards, and allowedRoles
	 * on the transition is still promoted into a role guard.
	 *
	 * @return void
	 */
	public function testANonListGuardsValueIsIgnoredButAllowedRolesArePromoted(): void {
		$guards = (new TransitionSpecReader())->extractGuards(
			transition: ['guards' => 'requiredField', 'allowedRoles' => ['Behandelaar']],
		);

		self::assertSame([['type' => 'roleGuard', 'allowedRoles' => ['Behandelaar']]], $guards);
	}//end testANonListGuardsValueIsIgnoredButAllowedRolesArePromoted()

	/**
	 * Non-array guard entries are dropped; the rest are normalised.
	 *
	 * @return void
	 */
	public function testNonArrayGuardEntriesAreDropped(): void {
		$guards = (new TransitionSpecReader())->extractGuards(
			transition: ['guards' => ['junk', ['type' => 'requiredField', 'fieldName' => 'besluit'], 7]],
		);

		self::assertSame([['type' => 'requiredField', 'fieldName' => 'besluit', 'field' => 'besluit']], $guards);
	}//end testNonArrayGuardEntriesAreDropped()

	/**
	 * The legacy `actions` spelling is read; non-array entries are dropped.
	 *
	 * @return void
	 */
	public function testTheLegacyActionsSpellingIsReadAndJunkDropped(): void {
		$actions = (new TransitionSpecReader())->extractActions(
			transition: ['actions' => [['type' => 'createTask'], 'junk', null]],
		);

		self::assertSame([['type' => 'createTask']], $actions);
	}//end testTheLegacyActionsSpellingIsReadAndJunkDropped()

	/**
	 * A non-list action value is read as no actions.
	 *
	 * @return void
	 */
	public function testANonListActionsValueIsNoActions(): void {
		self::assertSame([], (new TransitionSpecReader())->extractActions(transition: ['automaticActions' => 'createTask']));
	}//end testANonListActionsValueIsNoActions()

	/**
	 * Only a FAILED, SILENT role guard hides the transition.
	 *
	 * @return void
	 */
	public function testOnlyAFailedSilentRoleGuardHidesTheTransition(): void {
		$reader = new TransitionSpecReader();

		self::assertTrue($reader->isRoleHidden(evalResults: [
			['type' => 'requiredField', 'passed' => false, 'details' => ['silent' => true]],
			['type' => 'roleGuard', 'passed' => false, 'details' => ['silent' => true]],
		]));
		self::assertFalse($reader->isRoleHidden(evalResults: [
			['type' => 'roleGuard', 'passed' => false, 'details' => []],
		]));
		self::assertFalse($reader->isRoleHidden(evalResults: [
			['type' => 'roleGuard', 'passed' => true, 'details' => ['silent' => true]],
		]));
		self::assertFalse($reader->isRoleHidden(evalResults: [
			['type' => 'requiredField', 'passed' => false, 'details' => ['silent' => true]],
		]));
		self::assertFalse($reader->isRoleHidden(evalResults: []));
	}//end testOnlyAFailedSilentRoleGuardHidesTheTransition()
}//end class
