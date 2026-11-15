<?php

/**
 * The Review component binds each Kernel contract to its own implementation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Review
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Review;

use OCA\Dossiq\Kernel\Contracts\BatchSelectionInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentRelevanceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentTextInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentVerdictsInterface;
use OCA\Dossiq\Kernel\Contracts\PagesSeenInterface;
use OCA\Dossiq\Kernel\Contracts\RecallSamplesInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewBatchesInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewSummaryInterface;
use OCA\Dossiq\Kernel\Contracts\ReviewWritesInterface;
use OCA\Dossiq\Kernel\Contracts\StoppingRulesInterface;
use OCA\Dossiq\Kernel\Contracts\TriageRulesInterface;
use OCA\Dossiq\Review\BatchSelection;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\DocumentText;
use OCA\Dossiq\Review\DocumentVerdicts;
use OCA\Dossiq\Review\PagesSeen;
use OCA\Dossiq\Review\RecallSamples;
use OCA\Dossiq\Review\ReviewBatches;
use OCA\Dossiq\Review\ReviewContractRegistrar;
use OCA\Dossiq\Review\ReviewSummary;
use OCA\Dossiq\Review\ReviewWrites;
use OCA\Dossiq\Review\StoppingRules;
use OCA\Dossiq\Review\TriageRules;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Review\ReviewContractRegistrar
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class ReviewContractRegistrarTest extends TestCase {

	/**
	 * The aliases the registrar must register, in order.
	 *
	 * @var array<int, array{0: class-string, 1: class-string}>
	 */
	private const EXPECTED = [
		[BatchSelectionInterface::class, BatchSelection::class],
		[DocumentRelevanceInterface::class, DocumentRelevance::class],
		[DocumentTextInterface::class, DocumentText::class],
		[DocumentVerdictsInterface::class, DocumentVerdicts::class],
		[PagesSeenInterface::class, PagesSeen::class],
		[RecallSamplesInterface::class, RecallSamples::class],
		[ReviewBatchesInterface::class, ReviewBatches::class],
		[ReviewSummaryInterface::class, ReviewSummary::class],
		[ReviewWritesInterface::class, ReviewWrites::class],
		[StoppingRulesInterface::class, StoppingRules::class],
		[TriageRulesInterface::class, TriageRules::class],
	];

	/**
	 * Every contract is aliased exactly once, to its implementation, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testRegistersEachContractAliasOnce(): void {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: count(value: self::EXPECTED)))
			->method(constraint: 'registerServiceAlias')
			->willReturnCallback(
				callback: function (string $alias, string $target) use (&$aliases): void {
					$aliases[] = [$alias, $target];
				}
			);
		$context->expects($this->never())->method(constraint: 'registerService');

		(new ReviewContractRegistrar())->register(context: $context);

		$this->assertSame(expected: self::EXPECTED, actual: $aliases);
	}//end testRegistersEachContractAliasOnce()

	/**
	 * Each aliased implementation actually implements the contract it is bound to.
	 *
	 * @return void
	 */
	public function testEachTargetImplementsItsContract(): void {
		foreach (self::EXPECTED as [$contract, $target]) {
			$this->assertTrue(
				condition: is_subclass_of(object_or_class: $target, class: $contract),
				message: $target . ' does not implement ' . $contract
			);
		}
	}//end testEachTargetImplementsItsContract()
}//end class
