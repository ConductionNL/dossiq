<?php

/**
 * The sessions of a case type are read, keyed by session key, and a key declared twice is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use OCA\Dossiq\Service\Session\SessionDefinition;
use OCA\Dossiq\Service\Session\SessionDefinitionReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Session\SessionDefinitionReader
 * @uses \OCA\Dossiq\Service\Session\SessionDefinition
 */
class SessionDefinitionReaderTest extends TestCase {

	/**
	 * Every declared session is read, keyed by its key.
	 *
	 * @return void
	 */
	public function testEverySessionIsReadKeyedByKey(): void {
		$sessions = (new SessionDefinitionReader())->allOf(caseType: ['sessions' => [['key' => 'hearing'], ['key' => 'talk', 'label' => 'Talk']]]);

		self::assertSame(['hearing', 'talk'], array_keys($sessions));
		self::assertContainsOnlyInstancesOf(SessionDefinition::class, $sessions);
		self::assertSame('Talk', $sessions['talk']->label);
	}//end testEverySessionIsReadKeyedByKey()

	/**
	 * A case type without a sessions list declares none.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutSessionsDeclaresNone(): void {
		self::assertSame([], (new SessionDefinitionReader())->allOf(caseType: ['title' => 'x']));
	}//end testACaseTypeWithoutSessionsDeclaresNone()

	/**
	 * Two sessions with one key are refused.
	 *
	 * @return void
	 */
	public function testADuplicateKeyIsRefused(): void {
		$this->expectExceptionMessage("Session 'hearing' is declared twice.");
		(new SessionDefinitionReader())->allOf(caseType: ['sessions' => [['key' => 'hearing'], ['key' => 'hearing']]]);
	}//end testADuplicateKeyIsRefused()
}//end class
