<?php

/**
 * Unit tests for CorrespondenceLanguageConsumer::sentenceFor(): the sentence
 * says why a language is used, a stated preference first, then the rule
 * pipelinq applied.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pipelinq;

use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer
 *
 * @uses \OCA\Dossiq\Service\Pipelinq\PipelinqGateway
 */
class CorrespondenceLanguageSentenceTest extends TestCase {

	private CorrespondenceLanguageConsumer $consumer;

	protected function setUp(): void {
		parent::setUp();
		$this->consumer = new CorrespondenceLanguageConsumer(
			new PipelinqGateway($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	public function testAStatedPreferenceIsNamedWhateverTheRule(): void {
		$this->assertSame('This party asked to be written to in en.', $this->consumer->sentenceFor('en', 'instanceDefault', true));
		$this->assertSame('This party asked to be written to in de.', $this->consumer->sentenceFor('de', '', true));
	}//end testAStatedPreferenceIsNamedWhateverTheRule()

	public function testTheInstanceDefaultAndFallbackRulesAreExplained(): void {
		$this->assertSame(
			"No preference is recorded, so fr is used: it is this instance's default.",
			$this->consumer->sentenceFor('fr', 'instanceDefault', false)
		);
		$this->assertSame(
			'No preference is recorded and this instance sets no default, so nl is used.',
			$this->consumer->sentenceFor('nl', 'fallback', false)
		);
	}//end testTheInstanceDefaultAndFallbackRulesAreExplained()

	public function testAnyOtherRuleGetsThePlainSentence(): void {
		$this->assertSame('No preference is recorded, so nl is used.', $this->consumer->sentenceFor('nl', 'caseType', false));
		$this->assertSame('No preference is recorded, so nl is used.', $this->consumer->sentenceFor('nl', 'Fallback', false));
	}//end testAnyOtherRuleGetsThePlainSentence()
}//end class
