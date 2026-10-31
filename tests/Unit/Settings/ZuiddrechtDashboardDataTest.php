<?php

/**
 * Example data for the work dashboards: the case First today names, a
 * colleague Pieter covers for, and the unrouted messages of the inbox.
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
 * @spec openspec/specs/example-set-dashboard-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * DqMijnWerk "Vandaag eerst", DqAanMijToegewezen and DqPostvak have something to draw.
 *
 * @spec openspec/specs/example-set-dashboard-data/spec.md
 */
class ZuiddrechtDashboardDataTest extends TestCase {

	/**
	 * The Zuiddrecht set, parsed.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end set()

	/**
	 * The set's objects of one schema, by key.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objectsOf(string $schema): array {
		$found = [];
		foreach ($this->set()['objects'] as $object) {
			if ($object['schema'] === $schema) {
				$found[$object['key']] = $object;
			}
		}

		return $found;
	}//end objectsOf()

	/**
	 * The day a `{{date:n}}` token names, as n.
	 *
	 * @param string $token The token.
	 *
	 * @return integer
	 */
	private function dayOf(string $token): int {
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date:[+-]?\d+\}\}$/', string: $token);

		return (int)preg_replace(pattern: '/[^\d-]/', replacement: '', subject: $token);
	}//end dayOf()

	/**
	 * First today names Pieter's Woo request on Parkeervergunningen binnenstad:
	 * open, his, its four-week term ending on the install day.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-dashboard-data/spec.md#requirement-pieter-has-a-case-whose-term-ends-today
	 */
	public function testPieterHasACaseWhoseTermEndsToday(): void {
		$case = $this->objectsOf(schema: 'case')['woo-parkeervergunningen-binnenstad']['data'];
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $case['assignee']);
		$this->assertSame(expected: -28, actual: $this->dayOf(token: $case['startDate']));

		$terms = array_filter(
			$this->objectsOf(schema: 'deadlineInstance'),
			static fn (array $term): bool => $term['data']['case'] === '{{object:woo-parkeervergunningen-binnenstad.id}}'
		);
		$this->assertCount(expectedCount: 1, haystack: $terms);
		$term = array_values($terms)[0]['data'];
		$this->assertSame(expected: 'statutory', actual: $term['kind']);
		$this->assertSame(expected: 'lopend', actual: $term['status']);
		$this->assertSame(expected: 0, actual: $this->dayOf(token: $term['endDateCurrent']));
		$this->assertSame(expected: 0, actual: $this->dayOf(token: $term['endDateCalculated']));
	}//end testPieterHasACaseWhoseTermEndsToday()

	/**
	 * Close out your day lists Melding geluidsoverlast terras Markt, opened
	 * today and still without a note, two days past its term.
	 *
	 * @return void
	 */
	public function testPieterHasACaseTwoDaysLate(): void {
		$case = $this->objectsOf(schema: 'case')['melding-geluidsoverlast-terras-markt']['data'];
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $case['assignee']);

		$term = $this->objectsOf(schema: 'deadlineInstance')['termijn-geluidsoverlast-terras-markt']['data'];
		$this->assertSame(expected: '{{object:melding-geluidsoverlast-terras-markt.id}}', actual: $term['case']);
		$this->assertSame(expected: 'lopend', actual: $term['status']);
		$this->assertSame(expected: -2, actual: $this->dayOf(token: $term['endDateCurrent']));
	}//end testPieterHasACaseTwoDaysLate()

	/**
	 * Close out your day lists Bezwaar parkeerboete Havenstraat, opened today
	 * and still without a note, with "Termijn vandaag": its term ends on the install day.
	 *
	 * @return void
	 */
	public function testPietersObjectionTermEndsToday(): void {
		$part = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/20-team-queue.json'), true);
		$this->assertIsArray(actual: $part);
		$terms = array_column(array: $part['objects'], column_key: null, index_key: 'key');
		$term  = $terms['termijn-bezwaar-parkeerboete-havenstraat']['data'];

		$this->assertSame(expected: '{{object:bezwaar-parkeerboete-havenstraat.id}}', actual: $term['case']);
		$this->assertSame(expected: 0, actual: $this->dayOf(token: $term['endDateCurrent']));
		$this->assertSame(expected: 0, actual: $this->dayOf(token: $term['endDateCalculated']));
	}//end testPietersObjectionTermEndsToday()

	/**
	 * Pieter covers for Esra Demir this week, for all her work, so "Inclusief
	 * werk waarvoor u waarneemt" adds her open cases.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-dashboard-data/spec.md#requirement-pieter-covers-for-a-colleague
	 */
	public function testPieterCoversForAColleague(): void {
		$substitutions = $this->objectsOf(schema: 'substitution');
		$this->assertArrayHasKey(key: 'waarneming-pieter-voor-esra', array: $substitutions);
		$cover = $substitutions['waarneming-pieter-voor-esra']['data'];
		$this->assertSame(expected: '{{cast:esra.uid}}', actual: $cover['absentee']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $cover['substitute']);
		$this->assertSame(expected: 'all', actual: $cover['scope']);
		$this->assertSame(expected: 'active', actual: $cover['status']);
		$this->assertSame(expected: 'verlof', actual: $cover['reason']);
		$this->assertLessThanOrEqual(expected: 0, actual: $this->dayOf(token: $cover['startDate']));
		$this->assertGreaterThan(expected: 0, actual: $this->dayOf(token: $cover['endDate']));

		$hers = array_filter(
			$this->objectsOf(schema: 'case'),
			static fn (array $case): bool => ($case['data']['assignee'] ?? '') === '{{cast:esra.uid}}'
		);
		$this->assertGreaterThanOrEqual(expected: 2, actual: count($hers));
	}//end testPieterCoversForAColleague()

	/**
	 * Seventeen messages wait in the inbox: six name an open case by its
	 * number, eleven name none. Each comes from someone in the cast.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-dashboard-data/spec.md#requirement-the-inbox-holds-unrouted-messages
	 */
	public function testTheInboxHoldsSeventeenUnroutedMessages(): void {
		$messages = $this->objectsOf(schema: 'mailIntakeEntry');
		$this->assertCount(expectedCount: 17, haystack: $messages);

		$cases    = $this->objectsOf(schema: 'case');
		$matching = 0;
		$ids      = [];
		foreach ($messages as $key => $message) {
			$data = $message['data'];
			$this->assertSame(expected: 'dossiq', actual: $message['register'], message: $key);
			$this->assertSame(expected: 'inbox', actual: $data['outcome'], message: $key);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{cast:[a-z]+\.email\}\}$/', string: $data['sender'], message: $key);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:-?\d+ \d\d:\d\d\}\}$/', string: $data['receivedAt'], message: $key);
			$this->assertNotSame(expected: '', actual: trim($data['subject']), message: $key);
			$ids[] = $data['mailMessageId'];

			if (preg_match(pattern: '/\{\{object:([a-z0-9-]+)\.identifier\}\}/', subject: $data['subject'], matches: $named) === 1) {
				$this->assertArrayHasKey(key: $named[1], array: $cases, message: $key);
				$matching++;
				continue;
			}

			$this->assertDoesNotMatchRegularExpression(pattern: '/\b\d{4}-\d{3,}\b/', string: $data['subject'], message: $key);
		}

		$this->assertSame(expected: 6, actual: $matching);
		$this->assertSame(expected: $ids, actual: array_values(array_unique($ids)));
	}//end testTheInboxHoldsSeventeenUnroutedMessages()
	/**
	 * Close out your day has Pieter's day to show, as DqDagAfsluiten draws it:
	 * he opened two of his cases and the tree expert's advice task today
	 * without a note yet, and wrote notes on two other cases and on his
	 * hearing task, all on the install day.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-set-dashboard-data/spec.md#requirement-pieter-has-worked-today
	 */
	public function testPieterHasWorkedToday(): void {
		$set   = $this->set();
		$cases = $this->objectsOf(schema: 'case');
		$opened = [];
		foreach (($set['readStates'] ?? []) as $row) {
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $row['data']['user'], message: $row['key']);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:0 \d\d:\d\d\}\}$/', string: $row['data']['seenAt'], message: $row['key']);
			$opened[] = $row['data']['objectUuid'];
		}

		$noted = [];
		foreach (($set['notes'] ?? []) as $row) {
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $row['data']['user'], message: $row['key']);
			// Only a note of the install day is part of Pieter's day.
			if (preg_match(pattern: '/^\{\{datetime:0 \d\d:\d\d\}\}$/', subject: $row['data']['createdAt']) !== 1) {
				continue;
			}

			$this->assertNotSame(expected: '', actual: trim($row['data']['message']), message: $row['key']);
			$noted[] = $row['data']['objectUuid'];
		}

		$this->assertSame(
			expected: [
				'{{object:melding-geluidsoverlast-terras-markt.id}}',
				'{{object:bezwaar-parkeerboete-havenstraat.id}}',
				'{{task:advies-boomdeskundige.uuid}}',
			],
			actual: $opened
		);
		$this->assertSame(
			expected: [
				'{{object:woo-fietspad-besluitklaar.id}}',
				'{{task:hoorzitting-inplannen.uuid}}',
				'{{object:woo-parkeervergunningen-binnenstad.id}}',
			],
			actual: $noted
		);
		$tasks = array_column($set['tasks'], null, 'key');
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $tasks['hoorzitting-inplannen']['data']['assignee']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $tasks['advies-boomdeskundige']['data']['assignee']);
		foreach ([$opened[0], $opened[1], $noted[0], $noted[2]] as $reference) {
			$key = (string)preg_replace(pattern: '/^\{\{object:([a-z0-9-]+)\.id\}\}$/', replacement: '$1', subject: $reference);
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $cases[$key]['data']['assignee'], message: $key);
		}
	}//end testPieterHasWorkedToday()

	/**
	 * DqTaak: the hearing task is offered to Woo en informatie and carries
	 * Pieter's note of the day before about the committee's days.
	 *
	 * @return void
	 */
	public function testTheHearingTaskHasItsTeamAndNote(): void {
		$set  = $this->set();
		$task = array_column($set['tasks'], null, 'key')['hoorzitting-inplannen']['data'];
		$this->assertSame(expected: ['{{cast:woo-en-informatie.gid}}'], actual: $task['candidateGroups']);

		$note = array_column($set['notes'], null, 'key')['pieter-noteert-commissie-dagen']['data'];
		$this->assertSame(expected: '{{task:hoorzitting-inplannen.uuid}}', actual: $note['objectUuid']);
		$this->assertSame(expected: '{{datetime:-1 11:10}}', actual: $note['createdAt']);
	}//end testTheHearingTaskHasItsTeamAndNote()
}//end class
