<?php

/**
 * Dossiq TermResolution edge test.
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
 * The edges of the resolution: a row without a kind, padded values, and a
 * case type whose every norm is somebody else's.
 *
 * @covers \OCA\Dossiq\Service\Term\TermResolution
 *
 * @uses \OCA\Dossiq\Service\Term\TermAsk
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */
class TermResolutionEdgeTest extends TestCase {
	/**
	 * A row that declares no kind, or only whitespace, is a statutory term.
	 *
	 * @return void
	 */
	public function testABlankKindIsTheStatutoryTerm(): void {
		$resolution = $this->resolution(
			[
				['id' => 'blank', 'kind' => '  ', 'standardDurationDays' => 42],
				['id' => 'planned', 'kind' => TermKind::PLANNED, 'standardDurationDays' => 10],
			]
		);

		$this->assertSame('blank', $resolution->resolve(caseType: 'x', context: [])['definition']['id']);
		$this->assertSame('planned', $resolution->resolve(caseType: 'x', context: [], kind: TermKind::PLANNED)['definition']['id']);
		$this->assertNull($this->resolution([['id' => 'none', 'standardDurationDays' => 1]])->plannedDefinitionFor(caseType: 'x', context: []));
	}//end testABlankKindIsTheStatutoryTerm()

	/**
	 * Padding on either side does not stop a scope from matching, and the
	 * snapshot keeps the trimmed values.
	 *
	 * @return void
	 */
	public function testPaddedScopeValuesMatchAndAreTrimmedInTheSnapshot(): void {
		$resolution = $this->resolution(
			[
				['id' => 'general', 'standardDurationDays' => 56],
				['id' => 'urgent', 'priority' => ' spoed ', 'standardDurationDays' => '7'],
			]
		);

		$answer = $resolution->resolve(caseType: 'melding', context: ['priority' => 'spoed  ', 'service' => '   ']);

		$this->assertSame('urgent', $answer['definition']['id']);
		$this->assertSame('priority', $answer['resolvedFrom']);
		$this->assertSame('spoed', $answer['snapshot']['priority']);
		$this->assertSame('', $answer['snapshot']['service']);
		$this->assertSame('', $answer['snapshot']['organisation']);
		$this->assertSame(7, $answer['snapshot']['durationDays']);
		$this->assertSame('urgent', $answer['snapshot']['deadlineDefinition']);
		$this->assertSame('melding', $answer['snapshot']['caseType']);
	}//end testPaddedScopeValuesMatchAndAreTrimmedInTheSnapshot()

	/**
	 * A blank scope in the context asks for nothing, so the general term answers.
	 *
	 * @return void
	 */
	public function testABlankContextScopeFallsBackToTheCaseType(): void {
		$resolution = $this->resolution(
			[
				['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 20],
				['id' => 'general', 'organisation' => ' ', 'standardDurationDays' => 56],
			]
		);

		$answer = $resolution->resolve(caseType: 'x', context: ['organisation' => '  ']);

		$this->assertSame('general', $answer['definition']['id']);
		$this->assertSame(TermResolution::FALLBACK, $answer['resolvedFrom']);
	}//end testABlankContextScopeFallsBackToTheCaseType()

	/**
	 * When every norm of the kind is scoped and none matches, nothing resolves.
	 *
	 * @return void
	 */
	public function testOnlyUnmatchedScopedNormsResolveToNothing(): void {
		$resolution = $this->resolution(
			[
				['id' => 'alkmaar', 'organisation' => 'alkmaar', 'standardDurationDays' => 20],
				['id' => 'balie', 'service' => 'balie', 'standardDurationDays' => 3],
			]
		);

		$this->assertNull($resolution->resolve(caseType: 'x', context: ['organisation' => 'bergen']));
		$this->assertNull($resolution->resolve(caseType: 'x', context: []));
	}//end testOnlyUnmatchedScopedNormsResolveToNothing()

	/**
	 * A definition without a duration or id gives zero days and an empty reference.
	 *
	 * @return void
	 */
	public function testAMissingDurationAndIdGiveZeroAndEmpty(): void {
		$answer = $this->resolution([['caseType' => 'x']])->resolve(caseType: 'x', context: []);

		$this->assertSame(0, $answer['snapshot']['durationDays']);
		$this->assertSame('', $answer['snapshot']['deadlineDefinition']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $answer['snapshot']['resolvedAt']);
	}//end testAMissingDurationAndIdGiveZeroAndEmpty()

	/**
	 * The resolution over a store that answers these definitions for the asked case type.
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
