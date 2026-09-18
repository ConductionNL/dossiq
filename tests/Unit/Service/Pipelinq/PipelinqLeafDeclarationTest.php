<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use PHPUnit\Framework\TestCase;

/**
 * What the case declares of pipelinq, and what it must not.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-the-case-declares-pipelinqs-leaves-and-none-of-pipelinqs-data-req-plq-09
 */
class PipelinqLeafDeclarationTest extends TestCase {

	/**
	 * The schemas pipelinq owns. Dossiq declaring any of these would be the
	 * globally-unique-slug collision this whole programme exists to end.
	 *
	 * `contactmoment` is deliberately NOT on this list: dossiq still declares
	 * its own, and retiring it is the follow-up change this one builds the
	 * bridge for. Listing it here would fail on the state this change ships.
	 */
	private const PIPELINQ_OWNED = [
		'partyFieldSet',
		'partyIndicator',
		'partyIndicatorValue',
		'partyKind',
		'partyLink',
		'surveyInvitation',
		'surveyResponse',
		'programme',
		'programmeTask',
		'programmeWorkItem',
		'programmeCycle',
		'programmeEstimate',
		'estimationScale',
	];

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 4);
	}//end root()

	/**
	 * The case declares both pipelinq leaves.
	 *
	 * @return void
	 */
	public function testTheCaseDeclaresBothLeaves(): void {
		$fragment = json_decode(
			(string)file_get_contents($this->root() . '/lib/Settings/register.d/68-pipelinq-leaves.json'),
			true
		);

		$linkedTypes = ($fragment['components']['schemas']['case']['configuration']['linkedTypes'] ?? []);

		$this->assertContains('pipelinq-contact-moments', $linkedTypes);
		$this->assertContains('pipelinq-party', $linkedTypes);
	}//end testTheCaseDeclaresBothLeaves()

	/**
	 * No schema of pipelinq's is declared by dossiq.
	 *
	 * A schema slug is global per organisation, so a second declaration under
	 * one slug makes two definitions answer for one name and whichever is
	 * reached first wins. That is the collision the 2026-09-05 fleet audit
	 * found eighteen times.
	 *
	 * @return void
	 */
	public function testNoPipelinqOwnedSchemaIsDeclared(): void {
		$offenders = [];

		foreach (glob($this->root() . '/lib/Settings/register.d/*.json') as $path) {
			$fragment = json_decode((string)file_get_contents($path), true);
			if (is_array($fragment) === false) {
				continue;
			}

			foreach (array_keys((array)($fragment['components']['schemas'] ?? [])) as $slug) {
				if (in_array($slug, self::PIPELINQ_OWNED, true) === true) {
					$offenders[] = basename($path) . ': ' . $slug;
				}
			}
		}

		$monolith = json_decode(
			(string)file_get_contents($this->root() . '/lib/Settings/dossiq_register.json'),
			true
		);

		foreach (array_keys((array)($monolith['components']['schemas'] ?? [])) as $slug) {
			if (in_array($slug, self::PIPELINQ_OWNED, true) === true) {
				$offenders[] = 'dossiq_register.json: ' . $slug;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'pipelinq owns these, and dossiq declares and renders them: ' . implode(', ', $offenders)
		);
	}//end testNoPipelinqOwnedSchemaIsDeclared()
}//end class
