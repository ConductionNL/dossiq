<?php

/**
 * Unit tests for RulesFloorProposal.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Redaction;

use OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCA\Dossiq\Service\Redaction\RulesFloorProposal;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rules floor holds whatever the assistant answers, and its absence or failure leaves the rule spans alone.
 *
 * @covers \OCA\Dossiq\Service\Redaction\RulesFloorProposal
 * @uses   \OCA\Dossiq\Service\Assistant\HermiqAssistantException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RulesFloorProposalTest extends TestCase {

	/**
	 * The ask every case sends.
	 *
	 * @var array{text: string, context: array<string, string>}
	 */
	private const ASK = ['text' => 'Jan woont hier', 'context' => ['app' => 'dossiq', 'objectType' => 'document', 'objectRef' => 'doc-1']];

	/**
	 * The proposal over an assistant that is or is not there, and answers or throws.
	 *
	 * @param bool                                  $available Whether the assistant is there.
	 * @param array<string, mixed>|\Throwable|null  $answer    What detect-pii answers, or throws.
	 * @param LoggerInterface|null                  $logger    The logger.
	 *
	 * @return RulesFloorProposal The proposal builder.
	 */
	private function proposal(bool $available, array|\Throwable|null $answer = null, ?LoggerInterface $logger = null): RulesFloorProposal {
		$client = $this->createMock(originalClassName: HermiqAnonymisationClient::class);
		$client->method('isAvailable')->willReturn($available);
		if ($answer instanceof \Throwable) {
			$client->method('detectPii')->willThrowException($answer);
		} elseif ($answer !== null) {
			$client->method('detectPii')->with(self::ASK['text'], self::ASK['context'])->willReturn($answer);
		}

		return new RulesFloorProposal(
			hermiqClient: $client,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
	}//end proposal()

	/**
	 * Without the assistant the rule spans are the proposal, tagged as rules.
	 *
	 * @return void
	 */
	public function testWithoutTheAssistantTheRulesAreTheProposal(): void {
		$this->assertSame(
			expected: [
				'spans' => [['start' => 0, 'end' => 3, 'category' => 'name', 'source' => 'rule']],
				'source' => 'rules_only',
				'llmAvailable' => false,
			],
			actual: $this->proposal(available: false)->build(ruleSpans: [['start' => 0, 'end' => 3, 'category' => 'name']], ask: self::ASK)
		);
	}//end testWithoutTheAssistantTheRulesAreTheProposal()

	/**
	 * The assistant only adds: a duplicate, a malformed and an empty span are dropped, and the result is start-sorted.
	 *
	 * @return void
	 */
	public function testTheAssistantOnlyAddsToTheFloor(): void {
		$built = $this->proposal(
			available: true,
			answer: [
				'spans' => [
					['start' => 9, 'end' => 13, 'category' => 'address'],
					['start' => 0, 'end' => 3, 'category' => 'name'],
					['start' => 5, 'end' => 5, 'category' => 'name'],
					['start' => '1', 'end' => 2, 'category' => 'name'],
					'junk',
				],
			]
		)->build(ruleSpans: [['start' => 0, 'end' => 3, 'category' => 'name']], ask: self::ASK);

		$this->assertSame(expected: 'rules_plus_llm', actual: $built['source']);
		$this->assertTrue(condition: $built['llmAvailable']);
		$this->assertSame(
			expected: [
				['start' => 0, 'end' => 3, 'category' => 'name', 'source' => 'rule'],
				['start' => 9, 'end' => 13, 'category' => 'address', 'source' => 'llm'],
			],
			actual: $built['spans']
		);
	}//end testTheAssistantOnlyAddsToTheFloor()

	/**
	 * A failing assistant is logged and leaves the rule spans, naming the error.
	 *
	 * @return void
	 */
	public function testAFailingAssistantFallsBackToTheRules(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'WOOAnonymisationAssistService: LLM-assisted detection failed, falling back to rules-only',
			['app' => 'dossiq', 'error' => 'down']
		);

		$this->assertSame(
			expected: ['spans' => [], 'source' => 'rules_only_fallback', 'llmAvailable' => true, 'llmError' => 'down'],
			actual: $this->proposal(available: true, answer: new HermiqAssistantException('down', 503), logger: $logger)
				->build(ruleSpans: [], ask: self::ASK)
		);
	}//end testAFailingAssistantFallsBackToTheRules()

}//end class
