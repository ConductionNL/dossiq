<?php

/**
 * Unit tests for the redaction entity normaliser.
 *
 * Every source (filinq, hermiq spans, the officer's own list) arrives in its
 * own shape and leaves as `{type, value, source}`. These tests pin what is
 * dropped (an entity filinq marks to skip, a value-less one, a duplicate, a
 * row that is not an entity at all), how a type is named, and which source an
 * entity keeps.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
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
 * @spec openspec/specs/document-redaction/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Service\Redaction\RedactionEntities;
use PHPUnit\Framework\TestCase;

/**
 * Normalising, naming and de-duplicating redaction entities.
 *
 * @covers \OCA\Dossiq\Service\Redaction\RedactionEntities
 */
class RedactionEntitiesTest extends TestCase {

	private RedactionEntities $entities;

	protected function setUp(): void {
		$this->entities = new RedactionEntities();
	}//end setUp()

	/**
	 * Filinq's entities keep their confidence, relation and grounds; one marked to skip is dropped.
	 *
	 * @return void
	 */
	public function testFilinqEntitiesKeepTheirDetailsAndSkippedOnesAreDropped(): void {
		$result = $this->entities->fromFilinq([
			['entityType' => 'person_name', 'text' => ' Jan Jansen ', 'confidence' => 0.92, 'relationId' => 'r-1', 'bases' => ['5.1.2e'], 'other' => 'x'],
			['entityType' => 'EMAIL_ADDRESS', 'text' => 'jan@example.nl', 'skipAnonymization' => true],
			['type' => 'phone_number', 'value' => '0612345678', 'confidence' => null],
		]);

		$this->assertSame(
			[
				['type' => 'PERSON', 'value' => 'Jan Jansen', 'source' => 'filinq', 'confidence' => 0.92, 'relationId' => 'r-1', 'bases' => ['5.1.2e']],
				['type' => 'PHONE', 'value' => '0612345678', 'source' => 'filinq'],
			],
			$result
		);
	}//end testFilinqEntitiesKeepTheirDetailsAndSkippedOnesAreDropped()

	/**
	 * A span takes its value from the text when it carries none, and a rule span stays a rule.
	 *
	 * @return void
	 */
	public function testSpansReadTheirValueFromTheTextAndKeepARuleSource(): void {
		$text = 'Bel 0612345678 of mail jan@example.nl';

		$result = $this->entities->fromSpans(
			[
				['category' => 'phone', 'start' => 4, 'end' => 14, 'source' => 'rule'],
				['category' => 'email', 'text' => 'jan@example.nl'],
				['category' => '', 'text' => 'Utrecht'],
			],
			$text
		);

		$this->assertSame(
			[
				['type' => 'PHONE', 'value' => '0612345678', 'source' => 'rule'],
				['type' => 'EMAIL', 'value' => 'jan@example.nl', 'source' => 'hermiq'],
				['type' => 'UNKNOWN', 'value' => 'Utrecht', 'source' => 'hermiq'],
			],
			$result
		);
	}//end testSpansReadTheirValueFromTheTextAndKeepARuleSource()

	/**
	 * The officer's list keeps a known source, names an unknown one `officer` and skips non-entities.
	 *
	 * @return void
	 */
	public function testOfficerEntitiesKeepAKnownSourceAndSkipNonEntities(): void {
		$result = $this->entities->fromOfficer([
			['type' => 'PERSON', 'value' => 'Piet', 'source' => 'hermiq'],
			'not an entity',
			['type' => 'PERSON', 'value' => 'Klaas', 'source' => 'made-up'],
			['type' => 'PERSON', 'value' => 'Kees'],
		]);

		$this->assertSame(
			[
				['type' => 'PERSON', 'value' => 'Piet', 'source' => 'hermiq'],
				['type' => 'PERSON', 'value' => 'Klaas', 'source' => 'officer'],
				['type' => 'PERSON', 'value' => 'Kees', 'source' => 'officer'],
			],
			$result
		);
	}//end testOfficerEntitiesKeepAKnownSourceAndSkipNonEntities()

	/**
	 * A merge keeps the first of two entities equal by type and value (case-blind), and drops empty values.
	 *
	 * @return void
	 */
	public function testAMergeKeepsTheFirstOfEqualEntitiesAndDropsEmptyValues(): void {
		$first = $this->entities->fromOfficer([['type' => 'PERSON', 'value' => 'Jan Jansen']]);
		$second = $this->entities->fromSpans(
			[
				['category' => 'person', 'text' => 'JAN JANSEN'],
				['category' => 'email', 'text' => 'Jan Jansen'],
				['category' => 'person', 'text' => '   '],
			],
			''
		);

		$this->assertSame(
			[
				['type' => 'PERSON', 'value' => 'Jan Jansen', 'source' => 'officer'],
				['type' => 'EMAIL', 'value' => 'Jan Jansen', 'source' => 'hermiq'],
			],
			$this->entities->merge($first, $second)
		);
	}//end testAMergeKeepsTheFirstOfEqualEntitiesAndDropsEmptyValues()
}//end class
