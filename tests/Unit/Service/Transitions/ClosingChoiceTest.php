<?php

/**
 * A closing move says it closes the case, and offers the results it may close with.
 *
 * The lifecycle dialog confirmed a move into a closing status without asking
 * for a result, and the engine then refused it with "pick a result". The
 * offered move now carries `closesCase` and the case type's result types, so
 * the form asks before the post. These tests pin that answer, read off the
 * same `isFinal` question and the same resolver the close itself uses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Archival\ArchivalNominationDeriver;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionDeclarations
 * @covers \OCA\Dossiq\Service\Transitions\CaseResultWriter
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ClosingChoiceTest extends TestCase {

	/**
	 * Declarations over a result writer that knows `st-done` is final.
	 *
	 * @param CaseResultWriter $resultWriter The result writer.
	 *
	 * @return TransitionDeclarations
	 */
	private function declarations(CaseResultWriter $resultWriter): TransitionDeclarations {
		return new TransitionDeclarations(
			preconditions: new TransitionPreconditions(
				obligations: $this->createMock(originalClassName: ObligationService::class),
				declaration: new ObligationDeclaration(),
				evaluator: new DerivedStatusEvaluator(declaration: new StatusDeclaration()),
			),
			fourEyes: new FourEyesRule(),
			resultWriter: $resultWriter,
			store: $this->createMock(originalClassName: CaseStatusStore::class),
		);
	}//end declarations()

	/**
	 * A result writer whose case type `ct-1` offers two result types.
	 *
	 * @return CaseResultWriter
	 */
	private function resultWriter(): CaseResultWriter {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('resultTypesFor')->willReturnMap(
			[
				[
					'ct-1',
					[
						['id' => 'rt-granted', 'name' => 'Verleend'],
						['@self' => ['id' => 'rt-refused'], 'title' => 'Geweigerd'],
						['name' => 'No id, never offered'],
					],
				],
				['ct-other', []],
			]
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);

		$writer = $this->getMockBuilder(className: CaseResultWriter::class)
			->setConstructorArgs(
				[
					$settings,
					$resolver,
					$this->createMock(originalClassName: ArchivalNominationDeriver::class),
				]
			)
			->onlyMethods(methods: ['isFinalStatus'])
			->getMock();
		$writer->method('isFinalStatus')->willReturnCallback(
			static fn (string $statusTypeId): bool => $statusTypeId === 'st-done',
		);

		return $writer;
	}//end resultWriter()

	/**
	 * A move into a closing status offers the case type's results.
	 *
	 * @return void
	 */
	public function testAClosingMoveOffersTheResultTypes(): void {
		$choice = $this->declarations(resultWriter: $this->resultWriter())->closingChoice(
			transition: ['id' => 'close', 'toStatus' => 'st-done'],
			case: ['caseType' => 'ct-1'],
		);

		self::assertTrue(condition: $choice['closesCase']);
		self::assertSame(
			expected: [
				['id' => 'rt-granted', 'name' => 'Verleend'],
				['id' => 'rt-refused', 'name' => 'Geweigerd'],
			],
			actual: $choice['resultTypes'],
		);
	}//end testAClosingMoveOffersTheResultTypes()

	/**
	 * A move that does not close the case asks for nothing.
	 *
	 * @return void
	 */
	public function testAnOrdinaryMoveOffersNoResults(): void {
		$choice = $this->declarations(resultWriter: $this->resultWriter())->closingChoice(
			transition: ['id' => 'start', 'toStatus' => 'st-work'],
			case: ['caseType' => 'ct-1'],
		);

		self::assertSame(expected: ['closesCase' => false, 'resultTypes' => []], actual: $choice);
	}//end testAnOrdinaryMoveOffersNoResults()

	/**
	 * A closing move on a case type with no results still says it closes.
	 *
	 * @return void
	 */
	public function testAClosingMoveWithoutResultTypesStillSaysItCloses(): void {
		$choice = $this->declarations(resultWriter: $this->resultWriter())->closingChoice(
			transition: ['id' => 'close', 'toStatus' => 'st-done'],
			case: ['caseType' => 'ct-other'],
		);

		self::assertSame(expected: ['closesCase' => true, 'resultTypes' => []], actual: $choice);
	}//end testAClosingMoveWithoutResultTypesStillSaysItCloses()
}//end class
