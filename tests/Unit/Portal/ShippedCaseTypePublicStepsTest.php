<?php

/**
 * The public steps of every case type dossiq SHIPS.
 *
 * This test reads the register descriptors themselves rather than a
 * hand-written fixture, which is the whole point of it: a status type whose
 * `publicLabel` OpenRegister would silently drop, or a case type somebody adds
 * without public copy, is invisible to a test that invents its own statuses.
 * Here the shipped JSON IS the input, so the file and the assertion cannot
 * drift apart.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-resident-portal-design/specs/portal-contribution/spec.md#requirement-a-case-hands-the-portal-its-steps-req-srpd-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CaseSteps;
use PHPUnit\Framework\TestCase;

/**
 * Folding the SHIPPED case types into what a resident reads.
 */
class ShippedCaseTypePublicStepsTest extends TestCase {
	/**
	 * The public sequence each shipped case type must fold to, keyed by the
	 * `caseType` reference its status types carry.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EXPECTED = [
		// Base register, the four resident-facing case types.
		'@ref:omgevingsvergunning'             => ['Ontvangen', 'In behandeling', 'Besluit', 'Afgehandeld'],
		'@ref:subsidieaanvraag'                => ['Ontvangen', 'In behandeling', 'Besluit', 'Afgehandeld'],
		'@ref:klacht-behandeling'              => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
		'@ref:melding-openbare-ruimte'         => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
		// 58-case-type-versions: v2's Veiligheidstoets folds into the status
		// after it, so both versions read as the same three steps.
		'c4e7e000-0000-4000-a000-000000000101' => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
		'c4e7e000-0000-4000-a000-000000000102' => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
		// 81-woo-verzoek: eight internal statuses, five public steps.
		'3c0f5a00-0000-4000-a000-00000000a001' => ['Ontvangen', 'In behandeling', 'Besluit', 'Besluit genomen', 'Afgehandeld'],
		// 46-demo-cases-english, in the language that file declares.
		'building-permit-type'                 => ['Received', 'In progress', 'Decision', 'Completed'],
		'grant-application-type'               => ['Received', 'In progress', 'Decision', 'Completed'],
		'citizen-complaint-type'               => ['Received', 'In progress', 'Completed'],
		'foi-request-type'                     => ['Received', 'In progress', 'Decision', 'Completed'],
	];

	/**
	 * The descriptors that ship status types.
	 *
	 * @var array<int, string>
	 */
	private const DESCRIPTORS = [
		'lib/Settings/dossiq_register.json',
		'lib/Settings/register.d/46-demo-cases-english.json',
		'lib/Settings/register.d/58-case-type-versions.json',
		'lib/Settings/register.d/81-woo-verzoek.json',
	];

	/**
	 * Every shipped status type, grouped by the case type it belongs to.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The status types per case type.
	 */
	private function shippedStatusTypes(): array {
		$root     = dirname(__DIR__, 3);
		$grouped  = [];
		foreach (self::DESCRIPTORS as $relative) {
			$path = ($root.'/'.$relative);
			$this->assertFileExists($path, $relative.' ships');
			$raw = file_get_contents($path);
			$this->assertIsString($raw);
			$decoded = json_decode($raw, true);
			$this->assertIsArray($decoded, $relative.' is valid JSON');

			$objects = ($decoded['components']['objects'] ?? []);
			$this->assertIsArray($objects);
			foreach ($objects as $object) {
				if (is_array($object) === false) {
					continue;
				}

				if ((($object['@self'] ?? [])['schema'] ?? '') !== 'statusType') {
					continue;
				}

				// The importer resolves `@self.slug` to an id, and the
				// shipped descriptors carry no `id` of their own except in
				// 81-woo-verzoek. `CaseSteps::ordered()` drops a row with no
				// id, so a test reading the raw file would fold NOTHING and
				// read as a green empty list. Standing the slug in for the
				// id is what makes these rows what the portal actually gets.
				if (($object['id'] ?? null) === null) {
					$object['id'] = (string)(($object['@self'] ?? [])['slug'] ?? '');
				}

				$grouped[(string)($object['caseType'] ?? '')][] = $object;
			}//end foreach
		}//end foreach

		return $grouped;
	}//end shippedStatusTypes()

	/**
	 * Every shipped case type that has status types folds to the public
	 * sequence this test names, and every one of them is named here. A new
	 * case type that ships statuses without public copy fails on the second
	 * assertion rather than shipping the handler's words to a resident.
	 *
	 * @return void
	 */
	public function testEveryShippedCaseTypeFoldsToItsPublicSequence(): void {
		$grouped = $this->shippedStatusTypes();
		$this->assertNotEmpty($grouped, 'the descriptors ship status types');

		$this->assertSame(
			[],
			array_values(array_diff(array_keys($grouped), array_keys(self::EXPECTED))),
			'every shipped case type with statuses declares its public steps here'
		);
		$this->assertSame(
			[],
			array_values(array_diff(array_keys(self::EXPECTED), array_keys($grouped))),
			'every expectation names a case type that still ships'
		);

		$steps = new CaseSteps();
		foreach (self::EXPECTED as $caseType => $expected) {
			$this->assertSame(
				$expected,
				array_column($steps->forCase(case: [], statusTypes: $grouped[$caseType]), 'label'),
				$caseType.' folds to its public steps'
			);
		}
	}//end testEveryShippedCaseTypeFoldsToItsPublicSequence()

	/**
	 * Every public step a resident reads carries a description. The label
	 * alone says where the case stands; the description says what we are
	 * doing, and an empty one is the gap this change closed.
	 *
	 * @return void
	 */
	public function testEveryPublicStepExplainsItself(): void {
		$steps   = new CaseSteps();
		$grouped = $this->shippedStatusTypes();
		$missing = [];
		foreach (self::EXPECTED as $caseType => $expected) {
			foreach ($steps->forCase(case: [], statusTypes: $grouped[$caseType]) as $step) {
				if (trim((string)($step['description'] ?? '')) === '') {
					$missing[] = ($caseType.' / '.$step['label']);
				}
			}
		}

		$this->assertSame([], $missing, 'every public step carries a description');
	}//end testEveryPublicStepExplainsItself()

	/**
	 * The folding is real, not a coincidence of distinct names: these case
	 * types ship MORE statuses than they show steps, and the count is the
	 * proof a resident is told once what a handler tracks in stages.
	 *
	 * @return void
	 */
	public function testFoldingReducesTheStatusesAResidentSees(): void {
		$grouped = $this->shippedStatusTypes();
		$folds   = [
			'3c0f5a00-0000-4000-a000-00000000a001' => [8, 5],
			'c4e7e000-0000-4000-a000-000000000102' => [4, 3],
			'grant-application-type'               => [5, 4],
			'citizen-complaint-type'               => [4, 3],
		];

		$steps = new CaseSteps();
		foreach ($folds as $caseType => [$statuses, $public]) {
			$this->assertCount($statuses, $grouped[$caseType], $caseType.' ships '.$statuses.' statuses');
			$this->assertCount(
				$public,
				$steps->forCase(case: [], statusTypes: $grouped[$caseType]),
				$caseType.' shows '.$public.' public steps'
			);
		}
	}//end testFoldingReducesTheStatusesAResidentSees()
}//end class
