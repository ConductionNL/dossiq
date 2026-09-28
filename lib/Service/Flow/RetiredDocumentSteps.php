<?php

/**
 * The document translations: dossiq's createDocument and mergeTemplate as Filinq steps.
 *
 * Filinq owns document generation (`filinq.generate-document`). dossiq still
 * owns the case dossier, so a generated document is handed back to dossiq by
 * Filinq's DocumentGeneratedEvent and filed there by
 * {@see \OCA\Dossiq\Listener\DocumentGeneratedListener}. What that listener
 * needs to know about the document (its type and direction) travels in the
 * step's `metadata`, which Filinq passes through untouched.
 *
 * A mergeTemplate step with a `targetField` never filed anything: it wrote
 * the rendered text into one field of the case. It becomes a Filinq step
 * that writes that field and stores no file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\Dossiq\AppInfo\Application;

/**
 * Builds `filinq.generate-document` steps from retired document steps.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class RetiredDocumentSteps {

	/**
	 * Filinq's generate-document step.
	 *
	 * @var string
	 */
	public const GENERATE_DOCUMENT = 'filinq.generate-document';

	/**
	 * The metadata key that tells dossiq's listener to file the document on the case.
	 *
	 * @var string
	 */
	public const METADATA_TYPE = 'informatieobjecttype';

	/**
	 * The format a filed document is generated in.
	 *
	 * @var string
	 */
	private const FILED_FORMAT = 'pdf';

	/**
	 * Constructor.
	 *
	 * @param RetiredTemplateSyntax $syntax Template rewrites.
	 */
	public function __construct(
		private readonly RetiredTemplateSyntax $syntax,
	) {
	}//end __construct()

	/**
	 * `dossiq.action.createDocument`: render a letter and file it in the dossier.
	 *
	 * `templateSlug` carried the template TEXT on this step, and its merge
	 * fields were rendered first and read back as `{{case.mergeFields.x}}`;
	 * they are placed into the text before it is rewritten.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The generate-document step.
	 *
	 * @throws UnmappableStep When the step has no text or no document type.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function create(array $config): array {
		$text = $this->syntax->inlineMergeFields(
			template: (string)($config['templateSlug'] ?? ''),
			mergeFields: (array)($config['mergeFields'] ?? [])
		);

		$name = trim((string)($config['outputName'] ?? ''));
		if ($name === '') {
			$name = 'document.md';
		}

		return $this->filed(
			text: $text,
			name: $name,
			documentType: trim((string)($config['documentType'] ?? ''))
		);
	}//end create()

	/**
	 * `dossiq.action.mergeTemplate`: render text into a case field, or file it in the dossier.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The generate-document step.
	 *
	 * @throws UnmappableStep When the step has no text, or files a document without a type.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function merge(array $config): array {
		$text = (string)($config['template'] ?? ($config['templateSlug'] ?? ''));
		$targetField = trim((string)($config['targetField'] ?? ''));

		if ($targetField === '') {
			$name = trim((string)($config['templateName'] ?? ($config['name'] ?? '')));
			if ($name === '') {
				$name = 'Document';
			}

			return $this->filed(
				text: $text,
				name: $name,
				documentType: trim((string)($config['documentType'] ?? ''))
			);
		}

		return [
			'type' => self::GENERATE_DOCUMENT,
			'config' => [
				'template' => $this->text(text: $text),
				'storeFile' => false,
				'targetField' => $targetField,
				'requestingApp' => Application::APP_ID,
			],
		];
	}//end merge()

	/**
	 * A step that renders the text as a file and asks dossiq to file it on the case.
	 *
	 * @param string $text         The dossiq template text.
	 * @param string $name         The document's name, possibly with an extension.
	 * @param string $documentType The document type the dossier files it under.
	 *
	 * @return array{type: string, config: array<string, mixed>} The generate-document step.
	 *
	 * @throws UnmappableStep When there is no document type.
	 */
	private function filed(string $text, string $name, string $documentType): array {
		if ($documentType === '') {
			throw new UnmappableStep('the step names no document type, which the case dossier needs to file the document');
		}

		$title = (string)preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name);

		return [
			'type' => self::GENERATE_DOCUMENT,
			'config' => [
				'template' => $this->text(text: $text),
				'templateName' => $this->syntax->forDocument(template: $title, what: 'document name'),
				'filename' => $this->syntax->forDocument(template: $title, what: 'document name'),
				'format' => self::FILED_FORMAT,
				'storeFile' => true,
				'requestingApp' => Application::APP_ID,
				'metadata' => [
					self::METADATA_TYPE => $documentType,
					'direction' => 'outgoing',
					'addressees' => 'case',
				],
			],
		];
	}//end filed()

	/**
	 * The template text, rewritten and required.
	 *
	 * @param string $text The dossiq template text.
	 *
	 * @return string The Filinq template.
	 *
	 * @throws UnmappableStep When there is no text.
	 */
	private function text(string $text): string {
		if (trim($text) === '') {
			throw new UnmappableStep('the step has no template text');
		}

		return $this->syntax->forDocument(template: $text, what: 'template');
	}//end text()
}//end class
