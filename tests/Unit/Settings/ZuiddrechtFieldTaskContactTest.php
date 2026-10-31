<?php

/**
 * Example data for three boards: Pieter's field inspections today
 * (DqInspectie), his task with a checklist and a high priority (DqTaak) and
 * the calls and the desk visit on Henk Bakker's parking permit case
 * (DqZaakContact).
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
 * @spec openspec/specs/example-set-field-task-contact/spec.md#requirement-the-example-data-carries-what-the-inspection-task-and-contact-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Inspections today, a task with its checklist, a conversation over channels.
 *
 * @spec openspec/specs/example-set-field-task-contact/spec.md#requirement-the-example-data-carries-what-the-inspection-task-and-contact-boards-draw
 */
class ZuiddrechtFieldTaskContactTest extends TestCase {

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
	 * The fragment's objects of one schema, by key.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(string $schema): array {
		$found = array_filter($this->set()['objects'], static fn (array $object): bool => $object['schema'] === $schema);

		return array_column($found, null, 'key');
	}//end objects()

	/**
	 * DqInspectie: four inspections for Pieter today, one done, one under way, two planned, each on a case of the set.
	 *
	 * @return void
	 */
	public function testPieterHasFourInspectionsToday(): void {
		$inspections = $this->objects(schema: 'fieldInspection');
		$this->assertCount(expectedCount: 4, haystack: $inspections);

		$statuses = [];
		foreach ($inspections as $inspection) {
			$data = $inspection['data'];
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $data['inspectorRef']);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:0 \d\d:\d\d\}\}$/', string: $data['scheduledAt']);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{object:[a-z-]+\.id\}\}$/', string: $data['caseRef']);
			$statuses[] = $data['status'];
		}

		sort($statuses);
		$this->assertSame(expected: ['in_progress', 'planned', 'planned', 'synced'], actual: $statuses);
	}//end testPieterHasFourInspectionsToday()

	/**
	 * DqTaak: Pieter's task in execution, priority high, with a checklist of four items of which two are done.
	 *
	 * @return void
	 */
	public function testPieterHasATaskWithItsChecklist(): void {
		$tasks = array_column($this->set()['tasks'] ?? [], 'data', 'key');
		$this->assertArrayHasKey(key: 'hoorzitting-inplannen', array: $tasks);

		$task = $tasks['hoorzitting-inplannen'];
		$this->assertSame(expected: 'Hoorzitting inplannen', actual: $task['title']);
		$this->assertSame(expected: 'high', actual: $task['priority']);
		$this->assertSame(expected: 'active', actual: $task['state']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $task['assignee']);
		$this->assertCount(expectedCount: 4, haystack: $task['checklist']);

		$ids     = array_column($task['checklist'], 'id');
		$checked = array_filter(array_column($task['checklist'], 'checked'));
		$this->assertSame(expected: $ids, actual: array_unique($ids));
		$this->assertCount(expectedCount: 2, haystack: $checked);
	}//end testPieterHasATaskWithItsChecklist()

	/**
	 * DqZaakContact: four contact moments on Henk's parking permit case, over more than one channel, each naming the resident and the employee.
	 *
	 * @return void
	 */
	public function testHenksCaseHasAConversationOverChannels(): void {
		$moments = array_filter(
			$this->objects(schema: 'contactmoment'),
			static fn (array $moment): bool => $moment['data']['case'] === '{{object:parkeren-henk.id}}'
		);
		$this->assertCount(expectedCount: 4, haystack: $moments);

		$channels = array_unique(array_column(array_column($moments, 'data'), 'notificationChannel'));
		$this->assertGreaterThanOrEqual(expected: 2, actual: count($channels));
		$directions = array_unique(array_column(array_column($moments, 'data'), 'direction'));
		$this->assertEqualsCanonicalizing(expected: ['inbound', 'outbound'], actual: $directions);

		foreach ($moments as $moment) {
			$this->assertSame(expected: '{{cast:henk.bsn}}', actual: $moment['data']['geidentificeerdeBurgerId']);
			$this->assertStringStartsWith(prefix: '{{cast:', string: $moment['data']['kccEmployeeId']);
		}
	}//end testHenksCaseHasAConversationOverChannels()
}//end class
