<?php

/**
 * The Woo request case type's second version: the five steps the boards draw.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-the-woo-request-case-type-has-a-second-version-with-five-steps
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Woo\WooRequestIntake;
use PHPUnit\Framework\TestCase;

/**
 * Version 2 of the Woo request case type, its statuses, its types and its flow.
 */
class WooVerzoekVersionTwoSeedTest extends TestCase {

	private const V2 = '3c0f5a00-0000-4000-a000-00000000a002';

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged = [];

	/**
	 * Merge the register with its fragments, as an install does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$this->merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
	}//end setUp()

	/**
	 * The objects of a schema, by id when they carry one.
	 *
	 * @param string      $schema The schema.
	 * @param string|null $owner  The case type they belong to, or null for any.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(string $schema, ?string $owner = null): array {
		$out = [];
		foreach (($this->merged['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') !== $schema) {
				continue;
			}

			if ($owner === null || ($object['caseType'] ?? '') === $owner) {
				$out[] = $object;
			}
		}

		return $out;
	}//end objects()

	/**
	 * The case type with an id.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private function caseType(string $id): array {
		foreach ($this->objects(schema: 'caseType') as $type) {
			if (($type['id'] ?? '') === $id) {
				return $type;
			}
		}

		self::fail(message: 'no case type ' . $id);
	}//end caseType()

	/**
	 * The five statuses of version 2, in order.
	 *
	 * @return array<int, array<string, mixed>> The statuses.
	 */
	private function statuses(): array {
		$statuses = $this->objects(schema: 'statusType', owner: self::V2);
		usort($statuses, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		return $statuses;
	}//end statuses()

	/**
	 * The status of version 2 with a name.
	 *
	 * @param string $name The name.
	 *
	 * @return string The status id.
	 */
	private function statusNamed(string $name): string {
		return (string)array_column($this->statuses(), 'id', 'name')[$name];
	}//end statusNamed()

	/**
	 * Version 1 is superseded by version 2, and both share the identifier.
	 *
	 * @return void
	 */
	public function testVersionTwoSupersedesTheFirst(): void {
		$first = $this->caseType(id: WooRequestIntake::CASE_TYPE_ID);
		$second = $this->caseType(id: self::V2);

		self::assertSame(expected: 1, actual: $first['version']);
		self::assertSame(expected: self::V2, actual: $first['supersededBy']);
		self::assertSame(expected: 2, actual: $second['version']);
		self::assertSame(expected: WooRequestIntake::CASE_TYPE_ID, actual: $second['previousVersion']);
		self::assertSame(expected: WooRequestIntake::CASE_TYPE_IDENTIFIER, actual: $first['identifier']);
		self::assertSame(expected: WooRequestIntake::CASE_TYPE_IDENTIFIER, actual: $second['identifier']);
		self::assertFalse(condition: $second['isDraft']);
	}//end testVersionTwoSupersedesTheFirst()

	/**
	 * Five statuses, as the boards draw them, the last one final.
	 *
	 * @return void
	 */
	public function testVersionTwoHasTheFiveStepsTheBoardsDraw(): void {
		$statuses = $this->statuses();

		self::assertSame(expected: ['Ontvangen', 'In behandeling', 'Besluit', 'Publiceren', 'Afgehandeld'], actual: array_column($statuses, 'name'));
		self::assertSame(expected: [false, false, false, false, true], actual: array_column($statuses, 'isFinal'));
		self::assertSame(expected: ['intake', 'in-progress', 'review', 'review', 'closed'], actual: array_column($statuses, 'role'));
		self::assertSame(expected: $this->statusNamed(name: 'Ontvangen'), actual: $this->caseType(id: self::V2)['initialStatus']);
	}//end testVersionTwoHasTheFiveStepsTheBoardsDraw()

	/**
	 * Version 2 has its own result, decision and property types.
	 *
	 * @return void
	 */
	public function testVersionTwoHasItsOwnTypes(): void {
		$results = array_column($this->objects(schema: 'resultType', owner: self::V2), 'name');
		sort($results);
		self::assertSame(expected: ['Deels openbaar gemaakt', 'Ingetrokken', 'Niet openbaar gemaakt', 'Openbaar gemaakt'], actual: $results);

		$decisions = $this->objects(schema: 'decisionType', owner: self::V2);
		self::assertCount(expectedCount: 1, haystack: $decisions);
		self::assertSame(expected: [$decisions[0]['id']], actual: $this->caseType(id: self::V2)['decisionTypes']);

		self::assertSame(
			expected: array_column($this->objects(schema: 'propertyDefinition', owner: WooRequestIntake::CASE_TYPE_ID), 'name'),
			actual: array_column($this->objects(schema: 'propertyDefinition', owner: self::V2), 'name')
		);
	}//end testVersionTwoHasItsOwnTypes()

	/**
	 * The portal windows and the record import name version 2's statuses; the
	 * import moved to version 2, so it files on the version in use.
	 *
	 * @return void
	 */
	public function testThePortalAndTheImportNameVersionTwosStatuses(): void {
		$type = $this->caseType(id: self::V2);
		$early = [$this->statusNamed(name: 'Ontvangen'), $this->statusNamed(name: 'In behandeling')];

		self::assertSame(expected: $early, actual: $type['portalAmendmentWindow']['openStatuses']);
		self::assertSame(expected: $early, actual: $type['portalDocumentWindow']['openStatuses']);
		self::assertSame(expected: $early, actual: $type['portalWithdrawal']['openStatuses']);
		self::assertSame(expected: $this->statusNamed(name: 'Afgehandeld'), actual: $type['portalWithdrawal']['targetStatus']);

		$statuses = $type['recordImports'][0]['statuses'];
		self::assertSame(expected: $this->statusNamed(name: 'Ontvangen'), actual: $statuses['received']['status']);
		self::assertSame(expected: $this->statusNamed(name: 'In behandeling'), actual: $statuses['in_progress']['status']);
		self::assertSame(expected: $this->statusNamed(name: 'Afgehandeld'), actual: $statuses['withdrawn']['status']);
		$withdrawn = array_column($this->objects(schema: 'resultType', owner: self::V2), 'id', 'name')['Ingetrokken'];
		self::assertSame(expected: $withdrawn, actual: $statuses['withdrawn']['result']);
		self::assertArrayNotHasKey(key: 'recordImports', array: $this->caseType(id: WooRequestIntake::CASE_TYPE_ID));
	}//end testThePortalAndTheImportNameVersionTwosStatuses()

	/**
	 * The flow of version 2 walks the five steps with version 1's guards regrouped.
	 *
	 * @return void
	 */
	public function testTheFlowCarriesTheGuardsOntoFiveSteps(): void {
		$flow = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/packages/woo-verzoek/flows/woo-verzoek-version-2.json'), true);
		self::assertSame(expected: self::V2, actual: $flow['caseType']);
		self::assertSame(expected: $flow['id'], actual: $this->caseType(id: self::V2)['workflowDefinition']);
		self::assertSame(expected: array_column($this->statuses(), 'id'), actual: array_column(json_decode($flow['steps'], true), 'status'));

		$moves = [];
		foreach (json_decode($flow['transitions'], true) as $move) {
			$moves[$move['fromStatus'] . '>' . $move['toStatus']][] = $move;
		}

		$at = fn (string $from, string $to): array => ($moves[$this->statusNamed(name: $from) . '>' . $this->statusNamed(name: $to)] ?? []);

		self::assertCount(expectedCount: 1, haystack: $at('Ontvangen', 'In behandeling'));
		$toDecision = $at('In behandeling', 'Besluit')[0];
		self::assertSame(expected: ['gathered', 'assessed', 'redacted'], actual: array_column($toDecision['guards'], 'require'));
		self::assertSame(expected: 'wooPublicationStatus', actual: $at('Besluit', 'Publiceren')[0]['requiresSettled'][0]['field']);
		self::assertSame(expected: 'published', actual: $at('Publiceren', 'Afgehandeld')[0]['requiresSettled'][0]['value']);
		self::assertSame(
			expected: ['inadmissibilityReason', 'withdrawalReason'],
			actual: array_map(static fn (array $move): string => $move['requiresSettled'][0]['field'], $at('In behandeling', 'Afgehandeld'))
		);
		self::assertSame(expected: 'withdrawalReason', actual: $at('Ontvangen', 'Afgehandeld')[0]['requiresSettled'][0]['field']);
	}//end testTheFlowCarriesTheGuardsOntoFiveSteps()
}//end class
