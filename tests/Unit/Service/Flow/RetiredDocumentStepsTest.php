<?php

/**
 * Retired document steps unit tests.
 *
 * Pins what `dossiq.action.createDocument` and `dossiq.action.mergeTemplate`
 * become as Filinq generate-document steps: the filed shape, the field-write
 * shape, the defaults a retired configuration leaves out, and the refusals.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use PHPUnit\Framework\TestCase;

/**
 * Retired document steps as generate-document steps.
 *
 * @covers \OCA\Dossiq\Service\Flow\RetiredDocumentSteps
 * @uses   \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @uses   \OCA\Dossiq\Service\Flow\UnmappableStep
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 */
class RetiredDocumentStepsTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var RetiredDocumentSteps
	 */
	private RetiredDocumentSteps $steps;

	/**
	 * Build the steps over the real template syntax.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->steps = new RetiredDocumentSteps(syntax: new RetiredTemplateSyntax());
	}//end setUp()

	/**
	 * A createDocument step files a PDF on the case, merge fields placed and paths rewritten.
	 *
	 * @return void
	 */
	public function testCreateFilesARewrittenPdfOnTheCase(): void {
		$step = $this->steps->create(
			config: [
				'templateSlug' => 'Dear {{case.mergeFields.greeting}}, case {{case.number}}',
				'mergeFields' => ['greeting' => '{{case.applicant}}'],
				'outputName' => 'Besluit {{case.number}}.docx',
				'documentType' => 'besluit',
				'output' => 'letter',
			]
		);

		$this->assertSame(expected: 'filinq.generate-document', actual: $step['type']);
		$this->assertSame(
			expected: [
				'template' => 'Dear {{ item.applicant }}, case {{ item.number }}',
				'templateName' => 'Besluit {{ item.number }}',
				'filename' => 'Besluit {{ item.number }}',
				'format' => 'pdf',
				'storeFile' => true,
				'requestingApp' => 'dossiq',
				'output' => 'letter',
				'metadata' => [
					'informatieobjecttype' => 'besluit',
					'direction' => 'outgoing',
					'addressees' => 'case',
				],
			],
			actual: $step['config']
		);
	}//end testCreateFilesARewrittenPdfOnTheCase()

	/**
	 * Without a name or an output, the document is "document" and the result goes under actionResult.
	 *
	 * @return void
	 */
	public function testCreateFallsBackToDefaultNameAndOutput(): void {
		$step = $this->steps->create(
			config: ['templateSlug' => 'Body', 'documentType' => 'brief', 'outputName' => '  ', 'output' => ' ']
		);

		$this->assertSame(expected: 'document', actual: $step['config']['templateName']);
		$this->assertSame(expected: 'document', actual: $step['config']['filename']);
		$this->assertSame(expected: 'actionResult', actual: $step['config']['output']);
	}//end testCreateFallsBackToDefaultNameAndOutput()

	/**
	 * A filed document without a document type is refused: the dossier cannot file it.
	 *
	 * @return void
	 */
	public function testCreateWithoutDocumentTypeIsRefused(): void {
		$this->expectException(exception: UnmappableStep::class);
		$this->expectExceptionMessage(message: 'the step names no document type, which the case dossier needs to file the document');

		$this->steps->create(config: ['templateSlug' => 'Body', 'documentType' => '   ']);
	}//end testCreateWithoutDocumentTypeIsRefused()

	/**
	 * A filed document without text is refused.
	 *
	 * @return void
	 */
	public function testCreateWithoutTextIsRefused(): void {
		$this->expectException(exception: UnmappableStep::class);
		$this->expectExceptionMessage(message: 'the step has no template text');

		$this->steps->create(config: ['templateSlug' => " \n ", 'documentType' => 'brief']);
	}//end testCreateWithoutTextIsRefused()

	/**
	 * A mergeTemplate step with a target field writes the field and stores no file.
	 *
	 * @return void
	 */
	public function testMergeIntoAFieldStoresNoFile(): void {
		$step = $this->steps->merge(
			config: ['template' => 'Summary of {{case.title}} {{other.x}}', 'targetField' => ' summary ']
		);

		$this->assertSame(
			expected: [
				'type' => 'filinq.generate-document',
				'config' => [
					'template' => 'Summary of {{ item.title }} ',
					'storeFile' => false,
					'targetField' => 'summary',
					'requestingApp' => 'dossiq',
					'output' => 'actionResult',
				],
			],
			actual: $step
		);
	}//end testMergeIntoAFieldStoresNoFile()

	/**
	 * A mergeTemplate step reads templateSlug when there is no template.
	 *
	 * @return void
	 */
	public function testMergeFallsBackToTemplateSlug(): void {
		$step = $this->steps->merge(config: ['templateSlug' => 'From slug', 'targetField' => 'notes', 'output' => 'out']);

		$this->assertSame(expected: 'From slug', actual: $step['config']['template']);
		$this->assertSame(expected: 'out', actual: $step['config']['output']);
	}//end testMergeFallsBackToTemplateSlug()

	/**
	 * A mergeTemplate step without a target field files the document, named by templateName.
	 *
	 * @return void
	 */
	public function testMergeWithoutTargetFieldFilesTheDocument(): void {
		$step = $this->steps->merge(
			config: ['template' => 'Text', 'templateName' => 'Rapport.pdf', 'name' => 'ignored', 'documentType' => 'rapport']
		);

		$this->assertTrue(condition: $step['config']['storeFile']);
		$this->assertSame(expected: 'Rapport', actual: $step['config']['filename']);
		$this->assertSame(expected: 'rapport', actual: $step['config']['metadata']['informatieobjecttype']);
	}//end testMergeWithoutTargetFieldFilesTheDocument()

	/**
	 * Without templateName the step's name is used, and without either it is "Document".
	 *
	 * @return void
	 */
	public function testMergeNameFallsBackToNameThenDocument(): void {
		$named = $this->steps->merge(config: ['template' => 'Text', 'name' => 'Brief', 'documentType' => 't']);
		$unnamed = $this->steps->merge(config: ['template' => 'Text', 'documentType' => 't']);

		$this->assertSame(expected: 'Brief', actual: $named['config']['templateName']);
		$this->assertSame(expected: 'Document', actual: $unnamed['config']['templateName']);
	}//end testMergeNameFallsBackToNameThenDocument()

	/**
	 * Field-write text that is blank is refused.
	 *
	 * @return void
	 */
	public function testMergeIntoAFieldWithoutTextIsRefused(): void {
		$this->expectException(exception: UnmappableStep::class);
		$this->expectExceptionMessage(message: 'the step has no template text');

		$this->steps->merge(config: ['template' => '', 'targetField' => 'summary']);
	}//end testMergeIntoAFieldWithoutTextIsRefused()

	/**
	 * Twig syntax dossiq printed literally is refused rather than run by Filinq.
	 *
	 * @return void
	 */
	public function testTwigInTheTextIsRefused(): void {
		$this->expectException(exception: UnmappableStep::class);
		$this->expectExceptionMessage(message: 'the template contains "{%" or "{#"');

		$this->steps->merge(config: ['template' => '{% if x %}y{% endif %}', 'targetField' => 'summary']);
	}//end testTwigInTheTextIsRefused()
}//end class
