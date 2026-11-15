<?php

/**
 * A case type's always-available acts, read from its declaration.
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
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\AlwaysAvailableActDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Each case was recorded on the static reader this class replaced.
 *
 * @covers \OCA\Dossiq\Service\CaseType\AlwaysAvailableActDeclaration
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 */
class AlwaysAvailableActDeclarationTest extends TestCase {

	/**
	 * Declarations and the acts read from them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<string, string>>}>
	 */
	public static function declarations(): array {
		$act = static fn (string $id, string $label, string $description = '', string $effect = '', string $role = ''): array => [
			'id' => $id,
			'label' => $label,
			'description' => $description,
			'effect' => $effect,
			'requiredRole' => $role,
		];

		return [
			'absent' => [['id' => 'ct'], []],
			'not a list' => [['alwaysAvailableActs' => 42], []],
			'a JSON string' => [['alwaysAvailableActs' => '[{"id":" withdraw ","label":" Intrekken "}]'], [$act('withdraw', 'Intrekken')]],
			'broken JSON' => [['alwaysAvailableActs' => '{not json'], []],
			'a JSON scalar' => [['alwaysAvailableActs' => '"x"'], []],
			'entries without an id are dropped, the rest trimmed' => [
				['alwaysAvailableActs' => [
					'skip-me',
					['label' => 'no id'],
					['id' => '   '],
					['id' => 'suspend'],
					['id' => ' ask ', 'label' => ' Vraag ', 'description' => ' d ', 'effect' => ' pause ', 'requiredRole' => ' Jurist '],
				]],
				[$act('suspend', 'suspend'), $act('ask', 'Vraag', 'd', 'pause', 'Jurist')],
			],
		];
	}//end declarations()

	/**
	 * The acts a declaration yields.
	 *
	 * @param array<string, mixed>              $caseType The case type.
	 * @param array<int, array<string, string>> $expected The acts.
	 *
	 * @dataProvider declarations
	 *
	 * @return void
	 */
	public function testReadsTheDeclaredActs(array $caseType, array $expected): void {
		$this->assertSame(expected: $expected, actual: (new AlwaysAvailableActDeclaration())->actsOn(caseType: $caseType));
	}//end testReadsTheDeclaredActs()
}//end class
