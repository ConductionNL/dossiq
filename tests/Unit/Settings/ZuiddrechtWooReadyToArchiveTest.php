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
 * @spec openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-a-closed-woo-case-is-ready-to-archive
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The closed Woo case meets every archive readiness item it can meet in seed data.
 *
 * @spec openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-a-closed-woo-case-is-ready-to-archive
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
}//end class
