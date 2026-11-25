<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts every published flow a package ships can leave its case type's initial status.
 *
 * A new case starts at the case type's `initialStatus`. A published flow whose
 * first move starts anywhere else answers that case with no available
 * transitions, and executing one is refused as a from-status mismatch: the
 * case is stuck the moment it is created. The vth Handhavingstraject flow did
 * exactly that (it began at Vooraankondiging while Handhavingszaak starts at
 * Constatering), and nothing caught it until a live Newman run.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-case-type-pre-seeded-data
 */
class ShippedFlowsLeaveTheInitialStatusTest extends TestCase {

	private const PACKAGES = __DIR__ . '/../../../lib/Settings/packages';

	/**
	 * Every case type the packages ship, by id, with its initial status.
	 *
	 * @return array<string, array{title: string, initialStatus: string}>
	 */
	private function caseTypes(): array {
		$types = [];
		foreach (glob(self::PACKAGES . '/*/case-type*.json') ?: [] as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			$rows = $decoded['caseType'] ?? [];
			if (isset($rows['id']) === true) {
				$rows = [$rows];
			}

			foreach ($rows as $row) {
				$types[(string)$row['id']] = [
					'title' => (string)($row['title'] ?? ''),
					'initialStatus' => (string)($row['initialStatus'] ?? ''),
				];
			}
		}

		return $types;
	}

	/**
	 * Every published, active flow the packages ship, keyed by its file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function publishedFlows(): array {
		$flows = [];
		foreach (glob(self::PACKAGES . '/*/flows/*.json') ?: [] as $file) {
			$flow = json_decode((string)file_get_contents($file), true);
			if (($flow['isActive'] ?? false) !== true || ($flow['isDraft'] ?? false) === true) {
				continue;
			}

			$flows[basename(dirname($file, 2)) . '/' . basename($file)] = $flow;
		}

		return $flows;
	}

	/**
	 * The transitions of a flow, decoded whether stored as a string or a list.
	 *
	 * @param array<string, mixed> $flow The flow row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function transitionsOf(array $flow): array {
		$transitions = $flow['transitions'] ?? [];
		if (is_string($transitions) === true) {
			$transitions = json_decode($transitions, true);
		}

		return is_array($transitions) === true ? $transitions : [];
	}

	/**
	 * A published flow bound to a shipped case type has a move out of that case type's initial status.
	 *
	 * @return void
	 */
	public function testEveryPublishedFlowHasAMoveOutOfItsCaseTypesInitialStatus(): void {
		$types = $this->caseTypes();
		$flows = $this->publishedFlows();
		$this->assertNotEmpty($flows, 'The packages ship published flows.');

		$stuck = [];
		$checked = 0;
		foreach ($flows as $name => $flow) {
			$type = $types[(string)($flow['caseType'] ?? '')] ?? null;
			if ($type === null || $type['initialStatus'] === '') {
				continue;
			}

			$checked++;
			$from = array_column($this->transitionsOf($flow), 'fromStatus');
			if (in_array($type['initialStatus'], $from, true) === false) {
				$stuck[] = "{$name} (case type {$type['title']})";
			}
		}

		$this->assertGreaterThan(10, $checked, 'Most shipped flows name a shipped case type.');
		$this->assertSame([], $stuck, 'A new case of these case types would have no move: ' . implode(', ', $stuck));
	}

	/**
	 * The regular enforcement route starts at Constatering and its first move is the advance notice.
	 *
	 * @return void
	 */
	public function testHandhavingstrajectStartsAtTheCaseTypesInitialStatus(): void {
		$flow = $this->publishedFlows()['vth/handhavingstraject.json'];
		$initial = $this->caseTypes()[(string)$flow['caseType']]['initialStatus'];

		$steps = json_decode((string)$flow['steps'], true);
		$first = array_values(array_filter($steps, static fn (array $step): bool => ($step['isInitial'] ?? false) === true));
		$this->assertCount(1, $first, 'One initial step.');
		$this->assertSame($initial, $first[0]['status']);
		$this->assertSame(1, $first[0]['order']);

		$out = array_values(array_filter(
			$this->transitionsOf($flow),
			static fn (array $move): bool => $move['fromStatus'] === $initial
		));
		$this->assertSame(['Vooraankondiging'], array_column($out, 'toStatusName'));
	}
}
