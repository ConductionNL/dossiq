<?php

/**
 * Example data for the work boards: the cases Pieter Jansen works on
 * (DqMijnWerk, DqTaken, DqTaak) and the tasks on them, one per priority,
 * one finished and one still in the team's pool.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-pieters-work/spec.md#requirement-the-example-data-carries-the-cases-and-tasks-the-work-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter's cases and the tasks on them.
 *
 * @spec openspec/specs/example-set-pieters-work/spec.md#requirement-the-example-data-carries-the-cases-and-tasks-the-work-boards-draw
 */
class ZuiddrechtPietersWorkTest extends TestCase {

	/**
	 * The Zuiddrecht fragment, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end set()

	/**
	 * The four cases the work boards name, by title, with their case type.
	 *
	 * @return void
	 */
	public function testTheWorkBoardsCasesAreInTheSet(): void {
		$cases = [];
		foreach ($this->set()['objects'] as $object) {
			if ($object['schema'] === 'case') {
				$cases[$object['data']['title']] = $object['lookups']['caseType']['where']['identifier'];
			}
		}

		$expected = [
			'Bezwaar parkeerboete Havenstraat'      => 'bezwaar',
			'Parkeervergunningen binnenstad'        => 'woo-verzoek',
			'Woo-verzoek vergunningen evenementen'  => 'woo-verzoek',
			'Kapvergunning essen Parkweg'           => 'omgevingsvergunning',
		];
		foreach ($expected as $title => $type) {
			$this->assertSame(expected: $type, actual: ($cases[$title] ?? null), message: $title);
		}
	}//end testTheWorkBoardsCasesAreInTheSet()

	/**
	 * Seven tasks: the hearing on the objection, every priority, one done, one in the pool and Anouk's concept decision (DqTaken).
	 *
	 * @return void
	 */
	public function testTheTaskListHasEveryPriorityAndState(): void {
		$caseWork = array_filter($this->set()['tasks'], static fn (array $task): bool => isset($task['data']['kind']) === false);
		$tasks    = array_column($caseWork, 'data', 'key');
		$this->assertCount(expectedCount: 7, haystack: $tasks);
		$this->assertSame(expected: '{{object:bezwaar-parkeerboete-havenstraat.id}}', actual: $tasks['hoorzitting-inplannen']['objectUuid']);

		$priorities = array_unique(array_map(static fn (array $task): string => ($task['priority'] ?? 'normal'), $tasks));
		sort($priorities);
		$this->assertSame(expected: ['high', 'low', 'normal', 'urgent'], actual: $priorities);

		$states = array_count_values(array_column($tasks, 'state'));
		$this->assertSame(expected: 1, actual: ($states['completed'] ?? 0));
		$this->assertGreaterThanOrEqual(expected: 1, actual: ($states['available'] ?? 0));

		$pooled = array_filter($tasks, static fn (array $task): bool => isset($task['assignee']) === false);
		$this->assertCount(expectedCount: 1, haystack: $pooled);
		$this->assertNotEmpty(actual: array_values($pooled)[0]['candidateGroups']);
	}//end testTheTaskListHasEveryPriorityAndState()
}//end class
