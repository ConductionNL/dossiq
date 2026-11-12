<?php

/**
 * Files a document Filinq generated for a dossiq case in that case's dossier.
 *
 * Dossiq's own document steps (createDocument, mergeTemplate) are gone:
 * Filinq's `filinq.generate-document` renders the document now, and stores
 * it in the acting user's Files. What dossiq still owns is the dossier, so
 * this listener takes Filinq's DocumentGeneratedEvent and, when the document
 * was made for a dossiq case and names a document type, registers the file as
 * an informatieobject on that case through {@see GeneratedDocumentFiler}.
 *
 * IT LEAVES ALONE what is not its business: a generation with no object, one
 * for an object that is not a dossiq case, a field-only generation (no file),
 * one whose metadata names no document type, and one whose caller files the
 * document itself (the Generate document button).
 *
 * A FILING THAT FAILS IS NOT SWALLOWED. The event is dispatched inside
 * Filinq's generation, so the failure reaches the flow step, whose `onError`
 * policy decides, exactly as a failed upload did when dossiq's own step made
 * the document. A generated letter that silently never reached the dossier
 * is the failure the retired steps were written to avoid.
 *
 * The event class is Filinq's, named by string and registered only when it
 * exists: Filinq is optional at runtime.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Filinq\Event\DocumentGeneratedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Registers a generated file as an informatieobject on its case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 */
class DocumentGeneratedListener implements IEventListener {

	/**
	 * The Filinq event this listener is registered for.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Filinq\\Event\\DocumentGeneratedEvent';

	/**
	 * Constructor.
	 *
	 * @param CaseObjectReference    $cases  Recognises a dossiq case.
	 * @param GeneratedDocumentFiler $filer  Files the document on the case.
	 * @param LoggerInterface        $logger Logger.
	 */
	public function __construct(
		private readonly CaseObjectReference $cases,
		private readonly GeneratedDocumentFiler $filer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File the generated document when it belongs on a dossiq case.
	 *
	 * @param Event $event The Filinq event.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the document was meant for the case and could not be filed.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof DocumentGeneratedEvent) === false) {
			return;
		}

		$object = $this->caseToFileOn(event: $event);
		if ($object === null) {
			return;
		}

		$this->fileOn(event: $event, object: $object);
	}//end handle()

	/**
	 * The case the generated document is to be filed on, or null when it is not one this app files.
	 *
	 * Not when there is no object or file, when the caller filed it itself,
	 * when the metadata asks for no filing, or when the object is no case.
	 *
	 * @param DocumentGeneratedEvent $event The generated document.
	 *
	 * @return array<string, mixed>|null
	 */
	private function caseToFileOn(DocumentGeneratedEvent $event): ?array {
		$object = $event->getObject();
		if ($object === null || $event->getFileId() === null) {
			return null;
		}

		if ($this->filesHere(metadata: $event->getMetadata(), object: $object) === false) {
			return null;
		}

		return $object;
	}//end caseToFileOn()

	/**
	 * Whether this app files the document: the caller did not, the metadata asks for it, and the object is a case.
	 *
	 * @param array<string, mixed> $metadata The document's metadata.
	 * @param array<string, mixed> $object   The object it was generated for.
	 *
	 * @return bool
	 */
	private function filesHere(array $metadata, array $object): bool {
		return ($metadata[GeneratedDocumentFiler::FILED_BY_CALLER] ?? false) !== true
			&& $this->filer->wantsFiling(metadata: $metadata) === true
			&& $this->cases->isCase(register: $object['register'], schema: $object['schema']) === true;
	}//end filesHere()

	/**
	 * File the document in the case's dossier; a failed filing is logged and not swallowed.
	 *
	 * @param DocumentGeneratedEvent $event  The generated document.
	 * @param array<string, mixed>   $object The case.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the document could not be filed.
	 */
	private function fileOn(DocumentGeneratedEvent $event, array $object): void {
		$document = $event->getDocument();
		try {
			$this->filer->file(
				caseId: $object['id'],
				fileId: $event->getFileId(),
				title: $this->titleOf(event: $event),
				mime: (string)($document['mime'] ?? 'application/octet-stream'),
				metadata: $event->getMetadata()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a document Filinq generated for a case could not be filed in its dossier',
				['case' => $object['id'], 'file' => $event->getFileId(), 'requestingApp' => $event->getRequestingApp(), 'error' => $e->getMessage()]
			);

			throw new RuntimeException('The generated document could not be filed on the case: ' . $e->getMessage(), 0, $e);
		}
	}//end fileOn()

	/**
	 * The template's name, else the document's name, else "Document".
	 *
	 * @param DocumentGeneratedEvent $event The generated document.
	 *
	 * @return string
	 */
	private function titleOf(DocumentGeneratedEvent $event): string {
		$title = trim((string)($event->getTemplate()['name'] ?? ''));
		if ($title === '') {
			$title = (string)($event->getDocument()['name'] ?? 'Document');
		}

		return $title;
	}//end titleOf()
}//end class
