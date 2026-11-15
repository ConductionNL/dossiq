<?php

/**
 * The engine a decision template renders with comes from the case type.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\DecisionTemplateEngine;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for DecisionTemplateEngine.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\DecisionTemplateEngine
 */
class DecisionTemplateEngineTest extends TestCase {

	/**
	 * Build the resolver over a case of type `ct-1` with the given decision kinds.
	 *
	 * @param array<string, mixed>|null $kinds The case type's decisionDocuments, or null for none.
	 * @param array<string, mixed>|null $case The stored case; defaults to one of type `ct-1`.
	 *
	 * @return DecisionTemplateEngine The resolver.
	 */
	private function engines(?array $kinds, ?array $case = ['caseType' => 'ct-1']): DecisionTemplateEngine {
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->with('case-1')->willReturn($case);

		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseType = ['id' => 'ct-1'];
		if ($kinds !== null) {
			$caseType['decisionDocuments'] = $kinds;
		}

		$caseTypes->method('effectiveCaseType')->with('ct-1')->willReturn($caseType);

		return new DecisionTemplateEngine($store, $caseTypes, $this->createMock(LoggerInterface::class));
	}//end engines()

	/**
	 * The kind whose template is rendered names the vendor engine.
	 *
	 * @return void
	 */
	public function testTheKindOnTheTemplateNamesTheEngine(): void {
		$engines = $this->engines(
			[
				'grant' => ['template' => 'tpl-grant'],
				'refusal' => ['template' => 'tpl-refusal', 'engine' => 'vendor:xential'],
			]
		);

		$this->assertSame('vendor:xential', $engines->engineFor('case-1', 'tpl-refusal'));
		$this->assertSame('filinq', $engines->engineFor('case-1', 'tpl-grant'));
	}//end testTheKindOnTheTemplateNamesTheEngine()

	/**
	 * A template no kind declares renders with filinq.
	 *
	 * @return void
	 */
	public function testATemplateNoKindDeclaresRendersWithFilinq(): void {
		$engines = $this->engines(['grant' => ['template' => 'tpl-grant', 'engine' => 'vendor:smartdocuments']]);

		$this->assertSame('filinq', $engines->engineFor('case-1', 'tpl-other'));
	}//end testATemplateNoKindDeclaresRendersWithFilinq()

	/**
	 * A case type without decision kinds renders with filinq.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutKindsRendersWithFilinq(): void {
		$this->assertSame('filinq', $this->engines(null)->engineFor('case-1', 'tpl-grant'));
	}//end testACaseTypeWithoutKindsRendersWithFilinq()

	/**
	 * A case without a case type, or no case at all, renders with filinq.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeRendersWithFilinq(): void {
		$this->assertSame('filinq', $this->engines([], [])->engineFor('case-1', 'tpl-grant'));
		$this->assertSame('filinq', $this->engines([], null)->engineFor('case-1', 'tpl-grant'));
	}//end testACaseWithoutACaseTypeRendersWithFilinq()

	/**
	 * Without a case or template there is nothing to look up.
	 *
	 * @return void
	 */
	public function testNoCaseOrTemplateRendersWithFilinqWithoutReading(): void {
		$store = $this->createMock(CaseStatusStore::class);
		$store->expects($this->never())->method('loadCase');
		$engines = new DecisionTemplateEngine(
			$store,
			$this->createMock(CaseTypeResolver::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame('filinq', $engines->engineFor('', 'tpl-grant'));
		$this->assertSame('filinq', $engines->engineFor('case-1', ' '));
	}//end testNoCaseOrTemplateRendersWithFilinqWithoutReading()

	/**
	 * An engine of another shape is refused, not rendered with filinq.
	 *
	 * @return void
	 */
	public function testAMalformedEngineIsRefused(): void {
		$engines = $this->engines(['grant' => ['template' => 'tpl-grant', 'engine' => 'vendor:']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('decision_template_engine_invalid: decision kind grant');

		$engines->engineFor('case-1', 'tpl-grant');
	}//end testAMalformedEngineIsRefused()

	/**
	 * The vendor slug and name of an engine.
	 *
	 * @return void
	 */
	public function testVendorSlugAndName(): void {
		$this->assertSame('xential', DecisionTemplateEngine::vendorSlugOf('vendor:xential'));
		$this->assertNull(DecisionTemplateEngine::vendorSlugOf('filinq'));
		$this->assertNull(DecisionTemplateEngine::vendorSlugOf('vendor:'));
		$this->assertSame('SmartDocuments', DecisionTemplateEngine::vendorName('smartdocuments'));
		$this->assertSame('Xential', DecisionTemplateEngine::vendorName('xential'));
		$this->assertSame('acme', DecisionTemplateEngine::vendorName('acme'));
	}//end testVendorSlugAndName()
}//end class
