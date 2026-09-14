<?php

/**
 * The priority declaration has three copies. This is what stops them drifting.
 *
 * 🔴 A MIRROR DRIFTS SILENTLY, AND THAT IS THE WHOLE PROBLEM. The order and the
 * colour of a priority are DECLARED on the `case` schema, in
 * `lib/Settings/dossiq_register.json`, as `x-enum-order` and `x-enum-colours`.
 * Two readers cannot reach that file at the moment they need it:
 * `CasePriorityService` writes `priorityOrder` on every save, and the browser
 * draws the badge. Both therefore hold a copy.
 *
 * A copy that disagrees with the declaration does not fail. It renders a
 * plausible colour and sorts into a plausible order, and the only symptom is a
 * queue whose sort does not match its badges — which reads as a library bug
 * rather than as a stale constant. So each copy is pinned to the declaration
 * here, and the browser's copy is pinned the same way in
 * `tests/vitest/caseListPriority.spec.js`.
 *
 * The other half of this file is the literal `normal` the proposal counted:
 * three writes across two services, all of which had to go for the derivation
 * to be the only writer. A reader can check that in a diff once; this checks it
 * on every run, because a literal is exactly the kind of thing that comes back.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityService;
use PHPUnit\Framework\TestCase;

class CasePriorityDeclarationTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string The absolute path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The `case` schema's properties, as declared.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function caseProperties(): array {
		$raw = file_get_contents($this->root() . '/lib/Settings/dossiq_register.json');
		self::assertIsString($raw, 'the register declaration must be readable');

		$decoded = json_decode($raw, true);
		self::assertIsArray($decoded);

		return (array)$decoded['components']['schemas']['case']['properties'];
	}//end caseProperties()

	/**
	 * The schema still carries the priority field it always had: same values,
	 * same default, same facet, and no `format` (adding one to an existing
	 * property is a breaking change for every stored row).
	 */
	public function testThePriorityFieldItselfIsUnchanged(): void {
		$priority = (array)$this->caseProperties()['priority'];

		self::assertSame(CasePriorityService::PRIORITY_VALUES, $priority['enum']);
		self::assertSame('normal', $priority['default']);
		self::assertTrue($priority['facetable']);
		self::assertArrayNotHasKey('format', $priority);
	}//end testThePriorityFieldItselfIsUnchanged()

	/**
	 * The PHP copy of the order matches the declaration.
	 */
	public function testTheOrderConstantMatchesTheDeclaration(): void {
		$declared = (array)$this->caseProperties()['priority']['x-enum-order'];

		self::assertSame(CasePriorityService::PRIORITY_ORDER, $declared);
	}//end testTheOrderConstantMatchesTheDeclaration()

	/**
	 * The PHP copy of the colours matches the declaration.
	 */
	public function testTheColourConstantMatchesTheDeclaration(): void {
		$declared = (array)$this->caseProperties()['priority']['x-enum-colours'];

		self::assertSame(CasePriorityService::PRIORITY_COLOURS, $declared);
	}//end testTheColourConstantMatchesTheDeclaration()

	/**
	 * Every declared colour is a name from the NL Design System palette that
	 * `statusType.colour` already enumerates, never a hex value (REQ-PRI-05).
	 *
	 * The statusType enum is the palette this app resolves through
	 * `src/utils/statusColour.js`, so a name outside it would render grey and
	 * the declaration would have bought nothing.
	 */
	public function testEveryDeclaredColourIsAPaletteToken(): void {
		$raw = file_get_contents($this->root() . '/lib/Settings/dossiq_register.json');
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		$palette = (array)$decoded['components']['schemas']['statusType']['properties']['colour']['enum'];

		foreach (CasePriorityService::PRIORITY_COLOURS as $value => $colour) {
			self::assertContains($colour, $palette, $value);
			self::assertStringStartsNotWith('#', $colour, $value);
		}
	}//end testEveryDeclaredColourIsAPaletteToken()

	/**
	 * Every priority value has an order and a colour, and no value has two.
	 */
	public function testEveryPriorityValueIsDeclaredExactlyOnce(): void {
		$priority = (array)$this->caseProperties()['priority'];

		self::assertSame(CasePriorityService::PRIORITY_VALUES, array_keys((array)$priority['x-enum-order']));
		self::assertSame(CasePriorityService::PRIORITY_VALUES, array_keys((array)$priority['x-enum-colours']));
		self::assertSame(
			[1, 2, 3, 4],
			array_values((array)$priority['x-enum-order']),
			'the order must be dense and ascending, or a sort has ties'
		);
	}//end testEveryPriorityValueIsDeclaredExactlyOnce()

	/**
	 * The case carries impact and urgency, on the scale the service reads.
	 */
	public function testTheSchemaCarriesImpactAndUrgency(): void {
		$props = $this->caseProperties();

		self::assertSame(CasePriorityService::IMPACT_VALUES, (array)$props['impact']['enum']);
		self::assertSame(CasePriorityService::URGENCY_VALUES, (array)$props['urgency']['enum']);
		self::assertSame(CasePriorityService::DEFAULT_IMPACT, $props['impact']['default']);
		self::assertSame(CasePriorityService::DEFAULT_URGENCY, $props['urgency']['default']);
	}//end testTheSchemaCarriesImpactAndUrgency()

	/**
	 * The derived, floor and override fields exist, and the ones a person must
	 * never type are declared read-only.
	 */
	public function testTheDerivedAndStampedFieldsAreReadOnly(): void {
		$props = $this->caseProperties();

		foreach (
			[
				'priorityDerived',
				'priorityOrder',
				'priorityFloor',
				'priorityRaisedBy',
				'priorityOverrideBy',
				'priorityOverrideAt',
			] as $field
		) {
			self::assertArrayHasKey($field, $props, $field);
			self::assertTrue(($props[$field]['readOnly'] ?? false), $field . ' must be read-only');
		}

		// The two a person DOES set are deliberately not read-only.
		self::assertFalse(($props['priorityOverride']['readOnly'] ?? false));
		self::assertFalse(($props['priorityOverrideReason']['readOnly'] ?? false));
	}//end testTheDerivedAndStampedFieldsAreReadOnly()

	/**
	 * No property on the case is a second thing called a priority (REQ-PRI-06).
	 *
	 * Everything named `priority*` is either the derived value or a documented
	 * part of how it got there. A new field called, say, `casePriority` or
	 * `urgencyLevel` would put the app back where it started.
	 */
	public function testNothingElseOnTheCaseIsASecondPriority(): void {
		$named = array_values(
			array_filter(
				array_keys($this->caseProperties()),
				static fn (string $key): bool => str_contains(strtolower($key), 'priorit')
			)
		);

		self::assertSame(
			[
				'priority',
				'priorityDerived',
				'priorityOrder',
				'priorityFloor',
				'priorityRaisedBy',
				'priorityOverride',
				'priorityOverrideBy',
				'priorityOverrideAt',
				'priorityOverrideReason',
			],
			$named
		);
	}//end testNothingElseOnTheCaseIsASecondPriority()

	/**
	 * The case type carries the matrix and the two defaults.
	 */
	public function testTheCaseTypeCarriesTheMatrix(): void {
		$raw = file_get_contents($this->root() . '/lib/Settings/dossiq_register.json');
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		$props = (array)$decoded['components']['schemas']['caseType']['properties'];

		self::assertSame(CasePriorityService::IMPACT_VALUES, (array)$props['defaultImpact']['enum']);
		self::assertSame(CasePriorityService::URGENCY_VALUES, (array)$props['defaultUrgency']['enum']);

		$cell = (array)$props['priorityMatrix']['items']['properties'];
		self::assertSame(CasePriorityService::IMPACT_VALUES, (array)$cell['impact']['enum']);
		self::assertSame(CasePriorityService::URGENCY_VALUES, (array)$cell['urgency']['enum']);
		self::assertSame(CasePriorityService::PRIORITY_VALUES, (array)$cell['priority']['enum']);
	}//end testTheCaseTypeCarriesTheMatrix()

	/**
	 * No service writes the literal `normal` into a CASE priority any more.
	 *
	 * Three writes, in two files, counted in the proposal, plus the demo seed.
	 * The derivation is the only writer now, and a service that started writing
	 * the field again would silently win over it on create.
	 *
	 * A TASK priority is deliberately not in scope and is deliberately not
	 * matched here. `task.priority` belongs to the task engine, on a different
	 * object, and renaming or deriving it is not this change's business:
	 * REQ-PRI-06 asks that nothing else be a second priority ON A CASE.
	 */
	public function testNoServiceWritesTheLiteralCasePriority(): void {
		foreach (
			[
				'lib/Service/DsoIntakeService.php',
				'lib/Service/ComplaintService.php',
				'lib/Service/DemoCaseloadSeedDataService.php',
			] as $relative
		) {
			$source = file_get_contents($this->root() . '/' . $relative);
			self::assertIsString($source, $relative);

			$offenders = array_values(
				array_filter(
					preg_split('/\R/', $source) ?: [],
					static fn (string $line): bool => (
						preg_match("/'priority'\s*=>/", $line) === 1
						&& str_contains($line, 'taskSeed') === false
					)
				)
			);

			self::assertSame([], $offenders, $relative . ' must not write a case priority');
		}
	}//end testNoServiceWritesTheLiteralCasePriority()

	/**
	 * A copy carries the two facts behind a priority, and never somebody
	 * else's override or the floor a rule set on the original.
	 */
	public function testACopyCarriesTheFactsAndNotTheOverride(): void {
		$source = file_get_contents($this->root() . '/lib/Service/CaseCopyService.php');
		self::assertIsString($source);

		$reflection = new \ReflectionClass(\OCA\Dossiq\Service\CaseCopyService::class);
		$carried = (array)$reflection->getConstant('CARRIED');
		$never = (array)$reflection->getConstant('NEVER_COPIED');

		self::assertContains('impact', $carried);
		self::assertContains('urgency', $carried);

		foreach (
			[
				'priorityOverride',
				'priorityOverrideBy',
				'priorityOverrideAt',
				'priorityOverrideReason',
				'priorityFloor',
				'priorityRaisedBy',
			] as $field
		) {
			self::assertContains($field, $never, $field);
			self::assertNotContains($field, $carried, $field);
		}
	}//end testACopyCarriesTheFactsAndNotTheOverride()
}//end class
