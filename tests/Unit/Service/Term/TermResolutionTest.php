<?php

/**
 * Dossiq TermResolution test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\TermResolution;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;

/**
 * One case type, several agreed norms, and one declared order that decides
 * between them.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */
class TermResolutionTest extends TestCase {
	/**
	 * Scenario: One case type, two municipal norms.
	 *
	 * @return void
	 */
	public function testEachOrganisationGetsItsOwnNorm(): void {
		$resolution = $this->resolution(
			[
				$this->definition(['id' => 'general', 'standardDurationDays' => 56]),
				$this->definition(['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 42]),
				$this->definition(['id' => 'bergen', 'organisation' => 'bergen', 'standardDurationDays' => 28]),
			]
		);

		$alkmaar = $resolution->resolve(caseType: 'vergunning', context: ['organisation' => 'alkmaar']);
		$bergen = $resolution->resolve(caseType: 'vergunning', context: ['organisation' => 'bergen']);

		$this->assertSame(42, $alkmaar['definition']['standardDurationDays']);
		$this->assertSame('organisation', $alkmaar['resolvedFrom']);
		$this->assertSame(28, $bergen['definition']['standardDurationDays']);
	}//end testEachOrganisationGetsItsOwnNorm()

	/**
	 * Scenario: The fallback is the case type.
	 *
	 * @return void
	 */
	public function testAnOrganisationThatDeclaresNothingFallsBackToTheCaseType(): void {
		$resolution = $this->resolution(
			[
				$this->definition(['id' => 'general', 'standardDurationDays' => 56]),
				$this->definition(['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 42]),
			]
		);

		$answer = $resolution->resolve(caseType: 'vergunning', context: ['organisation' => 'schagen']);

		$this->assertSame('caseType', $answer['resolvedFrom']);
		$this->assertSame(56, $answer['definition']['standardDurationDays']);
	}//end testAnOrganisationThatDeclaresNothingFallsBackToTheCaseType()

	/**
	 * Somebody else's agreed norm is not a fallback. A case type with only
	 * narrower declarations and no general one resolves to nothing, which is
	 * better than resolving to a norm that was agreed with another party.
	 *
	 * @return void
	 */
	public function testAnotherPartysNormIsNeverAFallback(): void {
		$resolution = $this->resolution(
			[$this->definition(['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 42])]
		);

		$this->assertNull($resolution->resolve(caseType: 'vergunning', context: ['organisation' => 'bergen']));
	}//end testAnotherPartysNormIsNeverAFallback()

	/**
	 * The order is organisation, then service, then priority. It is asserted
	 * rather than assumed, because leaving it implicit is how two
	 * municipalities end up with the same configuration and different dates.
	 *
	 * @return void
	 */
	public function testTheOrderIsOrganisationThenServiceThenPriority(): void {
		$resolution = $this->resolution(
			[
				$this->definition(['id' => 'general', 'standardDurationDays' => 56]),
				$this->definition(['id' => 'urgent', 'priority' => 'urgent', 'standardDurationDays' => 7]),
				$this->definition(['id' => 'spoed', 'service' => 'spoed', 'standardDurationDays' => 14]),
				$this->definition(['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 42]),
			]
		);

		$context = ['organisation' => 'alkmaar', 'service' => 'spoed', 'priority' => 'urgent'];
		$this->assertSame('organisation', $resolution->resolve(caseType: 'v', context: $context)['resolvedFrom']);

		unset($context['organisation']);
		$this->assertSame('service', $resolution->resolve(caseType: 'v', context: $context)['resolvedFrom']);

		unset($context['service']);
		$this->assertSame('priority', $resolution->resolve(caseType: 'v', context: $context)['resolvedFrom']);
	}//end testTheOrderIsOrganisationThenServiceThenPriority()

	/**
	 * Scenario: Priority resolves a shorter lead time, read from the case's
	 * own derived priority and from no second field.
	 *
	 * @return void
	 */
	public function testPriorityResolvesAShorterLeadTime(): void {
		$resolution = $this->resolution(
			[
				$this->definition(['id' => 'general', 'standardDurationDays' => 56]),
				$this->definition(['id' => 'urgent', 'priority' => 'urgent', 'standardDurationDays' => 7]),
			]
		);

		$answer = $resolution->resolve(caseType: 'vergunning', context: ['priority' => 'urgent']);

		$this->assertSame(7, $answer['definition']['standardDurationDays']);
		$this->assertSame('urgent', $answer['snapshot']['priority']);
	}//end testPriorityResolvesAShorterLeadTime()

	/**
	 * A first-response term and a statutory term are different clocks, and
	 * resolving one never answers the other.
	 *
	 * @return void
	 */
	public function testAKindOnlyResolvesItsOwnClock(): void {
		$resolution = $this->resolution(
			[
				$this->definition(['id' => 'statutory', 'standardDurationDays' => 56]),
				$this->definition(['id' => 'ack', 'kind' => TermKind::FIRST_RESPONSE, 'standardDurationDays' => 5]),
			]
		);

		$statutory = $resolution->resolve(caseType: 'klacht', context: []);
		$first = $resolution->resolve(caseType: 'klacht', context: [], kind: TermKind::FIRST_RESPONSE);

		$this->assertSame(56, $statutory['definition']['standardDurationDays']);
		$this->assertSame(5, $first['definition']['standardDurationDays']);
	}//end testAKindOnlyResolvesItsOwnClock()

	/**
	 * A case type that declares no term at all resolves to nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoTermResolvesToNothing(): void {
		$this->assertNull($this->resolution([])->resolve(caseType: 'vergunning', context: []));
	}//end testACaseTypeWithNoTermResolvesToNothing()

	/**
	 * Scenario: A disputed date can be explained. The snapshot is a copy of
	 * what the decision was made from, not a reference to configuration that
	 * will have moved by the time anyone asks.
	 *
	 * @return void
	 */
	public function testTheSnapshotExplainsTheResolutionWithoutTheConfiguration(): void {
		$resolution = $this->resolution(
			[$this->definition(['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 42])]
		);

		$snapshot = $resolution->resolve(
			caseType: 'vergunning',
			context: ['organisation' => 'alkmaar', 'service' => 'regulier', 'priority' => 'normal']
		)['snapshot'];

		$this->assertSame('vergunning', $snapshot['caseType']);
		$this->assertSame('alkmaar', $snapshot['organisation']);
		$this->assertSame('regulier', $snapshot['service']);
		$this->assertSame('normal', $snapshot['priority']);
		$this->assertSame(42, $snapshot['durationDays']);
		$this->assertSame('alkmaar', $snapshot['deadlineDefinition']);
		$this->assertNotSame('', $snapshot['resolvedAt']);
	}//end testTheSnapshotExplainsTheResolutionWithoutTheConfiguration()

	/**
	 * One definition row.
	 *
	 * @param array<string, mixed> $overrides What this one declares.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function definition(array $overrides): array {
		return ($overrides + [
			'caseType' => 'vergunning',
			'kind' => TermKind::STATUTORY,
			'standardDurationDays' => 56,
		]);
	}//end definition()

	/**
	 * The resolution over a store that answers these definitions.
	 *
	 * @param array<int, array<string, mixed>> $definitions The active definitions.
	 *
	 * @return TermResolution The service under test.
	 */
	private function resolution(array $definitions): TermResolution {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('definitionsFor')->willReturn($definitions);

		return new TermResolution(terms: $terms);
	}//end resolution()
}//end class
