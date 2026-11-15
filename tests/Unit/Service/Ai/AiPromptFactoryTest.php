<?php

/**
 * Unit tests for AiPromptFactory: each AI feature's prompt names the case,
 * the document where one is given, and the JSON shape the model must answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\AiPromptFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Ai\AiPromptFactory
 */
class AiPromptFactoryTest extends TestCase {

	public function testClassificationNamesCaseAndDocument(): void {
		$this->assertSame(
			'Classify the following document for case Z-1. Document ID: D-9. Return JSON with fields: documentType (string), '
			. 'confidence (number 0-1), metadata (object with date, sender, subject).',
			(new AiPromptFactory())->classification('Z-1', 'D-9')
		);
	}//end testClassificationNamesCaseAndDocument()

	public function testExtractionFocusesOnADocumentOnlyWhenOneIsGiven(): void {
		$factory = new AiPromptFactory();

		$this->assertSame(
			'Extract structured data from documents in case Z-1. Focus on document D-9. Return JSON with fields: array of {name, value, confidence (0-1), source}.',
			$factory->extraction('Z-1', 'D-9')
		);
		$this->assertSame(
			'Extract structured data from documents in case Z-1. Return JSON with fields: array of {name, value, confidence (0-1), source}.',
			$factory->extraction('Z-1', null)
		);
	}//end testExtractionFocusesOnADocumentOnlyWhenOneIsGiven()

	public function testAQuestionCarriesTheNothingFoundAnswer(): void {
		$prompt = (new AiPromptFactory())->question('Z-1', 'Wanneer is het besluit genomen?');

		$this->assertStringStartsWith('Answer the following question in the context of case Z-1. Question: Wanneer is het besluit genomen?. ', $prompt);
		$this->assertStringEndsWith('{"answer": "Geen relevante informatie gevonden in de kennisbank", "sources": [], "confidence": 0}.', $prompt);
	}//end testAQuestionCarriesTheNothingFoundAnswer()

	public function testADocumentSummaryNamesItsDocumentAndOthersDoNot(): void {
		$factory = new AiPromptFactory();
		$tail = ' Return JSON with field: summary (string, 3-5 sentences in Dutch).';

		$this->assertSame('Generate a document summary for case Z-1. Summarize document D-9.' . $tail, $factory->summary('Z-1', 'document', 'D-9'));
		$this->assertSame('Generate a document summary for case Z-1.' . $tail, $factory->summary('Z-1', 'document', null));
		$this->assertSame('Generate a case summary for case Z-1.' . $tail, $factory->summary('Z-1', 'case', 'D-9'));
	}//end testADocumentSummaryNamesItsDocumentAndOthersDoNot()

	public function testRoutingAndNextStepAskForSuggestions(): void {
		$factory = new AiPromptFactory();

		$this->assertSame(
			'Suggest the best case worker for case Z-1 based on expertise and current workload. Return JSON with fields: suggestions (array of {userId, name, reason, confidence}).',
			$factory->routing('Z-1')
		);
		$this->assertSame(
			'Analyze the current state of case Z-1 and suggest what the case worker should do next. Return JSON with fields: suggestions (array of {action, reason, priority}).',
			$factory->nextStep('Z-1')
		);
	}//end testRoutingAndNextStepAskForSuggestions()
}//end class
