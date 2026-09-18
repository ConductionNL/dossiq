<?php

/**
 * The incident schema and the split declaration reach an existing instance.
 *
 * 🔴 A SCHEMA NOT CARRIED BY THE REGISTER IS REFUSED ON EVERY READ AND WRITE,
 * with "is not carried by register", and a schema with no config key resolves
 * to nothing and answers an empty list instead — which reads exactly like a
 * case with no incidents. Both are asserted here, because the register file,
 * the carriage list and the key map are three places that must agree and
 * nothing at runtime compares them.
 *
 * 🔴 A SAME-NUMBER VERSION COLLISION DOES NOT CONFLICT (dossiq#2938): two
 * branches taking one number merge in silence and the second import is
 * SKIPPED. The floor is the highest number claimed by the branches ahead of
 * this one in the queue, not the number this branch started from.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Cases\IncidentRecord;
use PHPUnit\Framework\TestCase;

/**
 * The shipped register carries the incident and the split bound.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentAndSplitShippedTest extends TestCase {
	/**
	 * The highest register version claimed ahead of this change. 0.20.3 was
	 * taken THREE times on the integration branch at once (#2923, #2937 and
	 * #2936), each writing the identical line, which merges in silence and
	 * makes every later import skip; the branches ahead of this one then take
	 * 0.20.6 to carry what accumulated under it, and that is the base this
	 * change now sits on.
	 *
	 * @var string
	 */
	private const FLOOR = '0.20.6';

	/**
	 * The shipped register.
	 *
	 * @return array<string, mixed> The register.
	 */
	private function register(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw, 'the register could not be read');

		return (array)json_decode((string)$raw, true);
	}//end register()

	/**
	 * The version moved past every number already claimed.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMovedPastEveryClaimedNumber(): void {
		$version = (string)($this->register()['info']['version'] ?? '');

		$this->assertTrue(
			version_compare($version, self::FLOOR, '>'),
			sprintf('The register is on %s and %s is already claimed; an import at or below it is skipped.', $version, self::FLOOR)
		);
	}//end testTheRegisterVersionMovedPastEveryClaimedNumber()

	/**
	 * The incident schema is defined AND carried, which are two things.
	 *
	 * @return void
	 */
	public function testTheIncidentSchemaIsDefinedAndCarried(): void {
		$register = $this->register();

		$this->assertArrayHasKey('incident', $register['components']['schemas']);
		$this->assertContains(
			'incident',
			$register['components']['registers']['dossiq']['schemas'],
			'a schema the register does not carry is refused on every read and write'
		);
	}//end testTheIncidentSchemaIsDefinedAndCarried()

	/**
	 * The incident carries both moments and its own owner.
	 *
	 * @return void
	 */
	public function testTheIncidentCarriesBothMomentsAndItsOwnOwner(): void {
		$properties = $this->register()['components']['schemas']['incident']['properties'];

		foreach (['case', 'eventDate', 'recordedAt', 'reporter', 'description', 'assignee', 'state', 'outcome'] as $field) {
			$this->assertArrayHasKey($field, $properties, sprintf('incident.%s is missing', $field));
		}

		// The states the service knows and the states the schema allows are
		// one list. Two lists drift, and the drift shows up as an incident
		// nobody can save.
		$this->assertSame(IncidentRecord::STATES, $properties['state']['enum']);
		// Ordered by when it HAPPENED, so the event date is a moment.
		$this->assertSame('date-time', $properties['eventDate']['format']);
	}//end testTheIncidentCarriesBothMomentsAndItsOwnOwner()

	/**
	 * The case type can bound a split, and the vocabulary is the one the
	 * policy enforces.
	 *
	 * @return void
	 */
	public function testTheCaseTypeCanBoundASplit(): void {
		$declaration = $this->register()['components']['schemas']['caseType']['properties']['splittableParts'];

		$this->assertSame(['documents', 'parties', 'tasks'], $declaration['items']['enum']);
		// No default in the schema, on purpose: absence is what means "all
		// three", and a default written here would be a second answer to that
		// question.
		$this->assertArrayNotHasKey('default', $declaration);
	}//end testTheCaseTypeCanBoundASplit()
}//end class
