<?php

/**
 * CaseTypeHandling::defaultHandler() unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/changes/archive/2026-05-11-zaak-intake-flow/design.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use PHPUnit\Framework\TestCase;

/**
 * The user a new case falls to, read off the declared block or the legacy `defaultAssignee` string.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class CaseTypeHandlingDefaultHandlerTest extends TestCase {

	/**
	 * The handler for a case type row.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The handler.
	 */
	private function handlerOf(array $caseType): string {
		return (new CaseTypeHandling())->defaultHandler(caseType: $caseType);
	}//end handlerOf()

	/**
	 * The declared block wins over the legacy property.
	 *
	 * @return void
	 */
	public function testDeclaredHandlerWins(): void {
		$caseType = ['handling' => ['defaultHandler' => 'piet'], 'defaultAssignee' => 'anna'];

		$this->assertSame(expected: 'piet', actual: $this->handlerOf(caseType: $caseType));
	}//end testDeclaredHandlerWins()

	/**
	 * A case type without a declared handler falls back to its `defaultAssignee` string.
	 *
	 * @return void
	 */
	public function testLegacyStringIsTheFallback(): void {
		$this->assertSame(expected: 'anna', actual: $this->handlerOf(caseType: ['defaultAssignee' => 'anna']));
		$this->assertSame(expected: '', actual: $this->handlerOf(caseType: []));
	}//end testLegacyStringIsTheFallback()

	/**
	 * A numeric user id stored as a number still names that user.
	 *
	 * @return void
	 */
	public function testNumericUserIdIsKept(): void {
		$this->assertSame(expected: '1001', actual: $this->handlerOf(caseType: ['defaultAssignee' => 1001]));
	}//end testNumericUserIdIsKept()

	/**
	 * A `defaultAssignee` that is not a user or group id leaves the case unclaimed, without a warning.
	 *
	 * The schema declares the property a string. An object or list in its
	 * place used to be cast to the user id 'Array'.
	 *
	 * @return void
	 */
	public function testNonStringAssigneeLeavesTheCaseUnclaimed(): void {
		$this->assertSame(expected: '', actual: $this->handlerOf(caseType: ['defaultAssignee' => ['id' => 'anna', 'displayName' => 'Anna']]));
		$this->assertSame(expected: '', actual: $this->handlerOf(caseType: ['defaultAssignee' => ['anna']]));
		$this->assertSame(expected: '', actual: $this->handlerOf(caseType: ['defaultAssignee' => true]));
		$caseType = ['handling' => ['defaultHandler' => ['id' => 'piet']], 'defaultAssignee' => (object)['id' => 'anna']];
		$this->assertSame(expected: '', actual: $this->handlerOf(caseType: $caseType));
	}//end testNonStringAssigneeLeavesTheCaseUnclaimed()
	/**
	 * The block answers every read switch at once, with the legacy fallbacks.
	 *
	 * The group falls back to the first intake destination that names a
	 * department, skipping a destination that is not a row at all, and that
	 * group leads the teams list ahead of the declared teams.
	 *
	 * @return void
	 */
	public function testTheBlockAppliesEveryFallback(): void {
		$caseType = [
			'handling' => [
				'teams' => ['juristen', ' ', 'vergunningen', 'juristen'],
				'automaticMessages' => ['extension', 'not-a-message', 'doorzending'],
				'intakeScreen' => 'woo-intake',
			],
			'intakeDestinations' => ['stray string', ['department' => ''], ['department' => 'vergunningen']],
			'defaultAssignee' => 'anna',
		];

		$this->assertSame(
			[
				'defaultGroup' => 'vergunningen',
				'teams' => ['vergunningen', 'juristen'],
				'defaultHandler' => 'anna',
				'automaticMessages' => ['extension', 'doorzending'],
				'intakeScreen' => 'woo-intake',
			],
			(new CaseTypeHandling())->block(caseType: $caseType)
		);
	}//end testTheBlockAppliesEveryFallback()

	/**
	 * A case type that declares nothing still sends every message, to nobody.
	 *
	 * @return void
	 */
	public function testAnEmptyCaseTypeBlockKeepsEveryMessage(): void {
		$this->assertSame(
			[
				'defaultGroup' => '',
				'teams' => [],
				'defaultHandler' => '',
				'automaticMessages' => CaseTypeHandling::MESSAGES,
				'intakeScreen' => '',
			],
			(new CaseTypeHandling())->block(caseType: ['intakeDestinations' => ['x', 7]])
		);
	}//end testAnEmptyCaseTypeBlockKeepsEveryMessage()
}//end class
