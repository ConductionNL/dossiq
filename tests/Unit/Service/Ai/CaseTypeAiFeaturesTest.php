<?php

/**
 * Tests for what a case type declares about AI.
 *
 * The rule under test is a privacy rule before it is a feature flag: a case
 * type that declares nothing must not have its cases sent anywhere to find out
 * what is available. The absence has to be answered locally.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use PHPUnit\Framework\TestCase;

class CaseTypeAiFeaturesTest extends TestCase {

	private CaseTypeAiFeatures $features;

	protected function setUp(): void {
		parent::setUp();
		$this->features = new CaseTypeAiFeatures();
	}//end setUp()

	/**
	 * A case type declaring a feature on the case surface offers it there.
	 *
	 * @return void
	 */
	public function testADeclaredFeatureIsOfferedOnItsSurface(): void {
		$caseType = ['aiFeatures' => ['summarise' => 'case', 'classify' => 'intake']];

		$this->assertSame(['summarise'], $this->features->on(caseType: $caseType, surface: 'case'));
		$this->assertSame(['classify'], $this->features->on(caseType: $caseType, surface: 'intake'));
	}//end testADeclaredFeatureIsOfferedOnItsSurface()

	/**
	 * A case type declaring nothing offers nothing, and has nothing to ask.
	 *
	 * The second assertion is the one that keeps the case off the network: the
	 * caller checks it BEFORE it calls, so an undeclared case type is answered
	 * locally rather than rendered empty after a request.
	 *
	 * @return void
	 */
	public function testAnUndeclaredCaseTypeOffersNothingAndAsksNothing(): void {
		$this->assertSame([], $this->features->on(caseType: [], surface: 'case'));
		$this->assertFalse($this->features->anyDeclared(caseType: []));
	}//end testAnUndeclaredCaseTypeOffersNothingAndAsksNothing()

	/**
	 * 🔑 `none` IS A DECLARATION, and it still offers nothing.
	 *
	 * An administrator who considered a feature and switched it off has said
	 * something; a later reader needs that apart from a case type nobody has
	 * looked at. It is declared, and it is not placed anywhere.
	 *
	 * @return void
	 */
	public function testNoneIsDeclaredAndStillOffersNothing(): void {
		$caseType = ['aiFeatures' => ['summarise' => 'none']];

		$this->assertSame([], $this->features->on(caseType: $caseType, surface: 'case'));
		$this->assertSame([], $this->features->on(caseType: $caseType, surface: 'none'));
		$this->assertFalse($this->features->anyDeclared(caseType: $caseType), 'nothing is placed, so nothing is asked');
		$this->assertSame(['summarise' => 'none'], $this->features->declared(caseType: $caseType));
	}//end testNoneIsDeclaredAndStillOffersNothing()

	/**
	 * A surface this app does not know is dropped, not guessed at.
	 *
	 * Placing a feature somewhere nobody asked for is worse than not placing
	 * it: the author gets a feature that visibly does not appear rather than
	 * one that appears in the wrong place.
	 *
	 * @return void
	 */
	public function testAnUnknownSurfaceIsDroppedRatherThanGuessed(): void {
		$caseType = ['aiFeatures' => ['summarise' => 'sidebar', 'classify' => 'case']];

		$this->assertSame(['classify'], $this->features->on(caseType: $caseType, surface: 'case'));
		$this->assertSame(['classify' => 'case'], $this->features->declared(caseType: $caseType));
	}//end testAnUnknownSurfaceIsDroppedRatherThanGuessed()

	/**
	 * One case type's declaration is not another's.
	 *
	 * The spec's own scenario: a handler opening a case of each sees the first
	 * offer summarising and the second offer nothing.
	 *
	 * @return void
	 */
	public function testOneCaseTypesDeclarationIsNotAnothers(): void {
		$declaring = ['aiFeatures' => ['summarise' => 'case']];
		$silent = ['aiFeatures' => []];

		$this->assertSame(['summarise'], $this->features->on(caseType: $declaring, surface: 'case'));
		$this->assertSame([], $this->features->on(caseType: $silent, surface: 'case'));
	}//end testOneCaseTypesDeclarationIsNotAnothers()

	/**
	 * A malformed declaration contributes nothing rather than throwing.
	 *
	 * A case type is administered data; a string where an object belongs is a
	 * mistake somebody made, not a reason a case page cannot open.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationContributesNothing(): void {
		$this->assertSame([], $this->features->declared(caseType: ['aiFeatures' => 'summarise']));
		$this->assertSame([], $this->features->declared(caseType: ['aiFeatures' => ['summarise' => ['case']]]));
		$this->assertSame([], $this->features->declared(caseType: ['aiFeatures' => ['' => 'case']]));
	}//end testAMalformedDeclarationContributesNothing()
}//end class
