<?php

/**
 * The Zuiddrecht example set seeds a closed Woo case ready to archive, as
 * DqArchiveren draws it: ended, a result and a decision recorded, and the
 * case type's retention facts with the archive status still to archive.
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
 * @spec openspec/specs/example-set-lindelaan-in-progress/spec.md#requirement-a-closed-woo-case-is-ready-to-archive
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The closed Woo case meets every archive readiness item it can meet in seed data.
 *
 * @spec openspec/specs/example-set-lindelaan-in-progress/spec.md#requirement-a-closed-woo-case-is-ready-to-archive
 */
class ZuiddrechtWooReadyToArchiveTest extends TestCase {

	/**
	 * The set.
	 *
	 * @return array<string, mixed> The set.
	 */
	private function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return $set;
	}//end set()

	/**
	 * The entry with this key in `objects` or `changes`.
	 *
	 * @param string $list `objects` or `changes`.
	 * @param string $key  The key.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(string $list, string $key): array {
		foreach ((array)$this->set()[$list] as $entry) {
			if (($entry['key'] ?? '') === $key) {
				return $entry;
			}
		}

		$this->fail(message: "No $key in $list");
	}//end entry()

	/**
	 * The case is closed, ended by finishing, and carries the retention facts.
	 *
	 * @return void
	 */
	public function testTheCaseIsClosedWithItsRetentionFacts(): void {
		$case = $this->entry(list: 'objects', key: 'woo-zaaksysteem');
		$this->assertSame(expected: 'Afgehandeld', actual: $case['lookups']['status']['where']['name']);
		$this->assertSame(expected: 'finish', actual: $case['data']['endingAct']);
		$this->assertSame(expected: 'vernietigen', actual: $case['data']['archiveNomination']);
		$this->assertSame(expected: 'nog_te_archiveren', actual: $case['data']['archiveStatus']);
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date:\d{4}\}\}$/', string: $case['data']['archiveActionDate']);
		$this->assertArrayNotHasKey(key: 'archived', array: $case['data']);
	}//end testTheCaseIsClosedWithItsRetentionFacts()

	/**
	 * Its result and its decision are on the case, through the set's change
	 * (the decision is made after the case, so the case names it afterwards).
	 *
	 * @return void
	 */
	public function testTheResultAndDecisionAreRecordedOnTheCase(): void {
		$change   = $this->entry(list: 'changes', key: 'woo-zaaksysteem-uitkomst');
		$decision = $this->entry(list: 'objects', key: 'woo-zaaksysteem-besluit');
		$this->assertSame(expected: 'woo-zaaksysteem', actual: $change['object']);
		$this->assertSame(expected: '{{object:woo-zaaksysteem-resultaat.id}}', actual: $change['set']['result']);
		$this->assertSame(expected: ['{{object:woo-zaaksysteem-besluit.id}}'], actual: $change['set']['decisions']);
		$this->assertSame(expected: '{{object:woo-zaaksysteem.id}}', actual: $decision['data']['case']);
	}//end testTheResultAndDecisionAreRecordedOnTheCase()
	/**
	 * Seven documents are on the case through zaakinformatieobject rows, all
	 * final ("Documents final: 7 of 7"), each with its file; the five the
	 * decision rules on carry the decision's tally.
	 *
	 * @return void
	 */
	public function testSevenFinalDocumentsAndTheDecisionsTally(): void {
		$byKey = [];
		foreach ((array)$this->set()['objects'] as $object) {
			$byKey[(string)$object['key']] = $object;
		}

		$documents   = [];
		$assessments = [];
		foreach ($byKey as $object) {
			$data = (array)($object['data'] ?? []);
			if (($object['schema'] ?? '') === 'zaakinformatieobject' && ($data['case'] ?? '') === '{{object:woo-zaaksysteem.id}}') {
				$documents[] = substr((string)$data['informatieobject'], strlen('{{object:'), -strlen('.id}}'));
			}

			if (($object['schema'] ?? '') === 'wooDocumentAssessment' && ($data['caseRef'] ?? '') === '{{object:woo-zaaksysteem.id}}') {
				$assessments[] = (string)$data['classification'];
			}
		}

		$this->assertCount(expectedCount: 7, haystack: array_unique($documents));
		foreach ($documents as $key) {
			$this->assertSame(expected: 'informatieobject', actual: $byKey[$key]['schema']);
			$this->assertSame(expected: 'final', actual: $byKey[$key]['data']['status']);
			$this->assertFileExists(filename: __DIR__.'/../../../lib/Settings/example-sets/'.$byKey[$key]['files'][0]['path']);
		}

		$tally = array_count_values($assessments);
		ksort($tally);
		$summary = (array)$byKey['woo-zaaksysteem-besluit']['data']['wooSummary'];
		ksort($summary);
		$this->assertSame(expected: $summary, actual: $tally);
	}//end testSevenFinalDocumentsAndTheDecisionsTally()

	/**
	 * The archive dialog (CnActionDialog, `case-archive` in the manifest)
	 * shows the case fields its facts name, and the case carries each one.
	 *
	 * @return void
	 */
	public function testTheArchiveDialogsFactsAreSeeded(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__.'/../../../src/manifest.json'), true);
		$fields   = [];
		array_walk_recursive(
			$manifest,
			static function (mixed $value, mixed $key) use (&$fields): void {
				if ($key === 'value' && is_string($value) === true && preg_match('/^\{(archive\w+)\}$/', $value, $match) === 1) {
					$fields[] = $match[1];
				}
			}
		);

		$this->assertNotSame(expected: [], actual: $fields);
		$case = $this->entry(list: 'objects', key: 'woo-zaaksysteem');
		foreach (array_unique($fields) as $field) {
			$this->assertArrayHasKey(key: $field, array: $case['data']);
		}
	}//end testTheArchiveDialogsFactsAreSeeded()
}//end class
