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
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
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
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
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
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof DocumentGeneratedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$fileId = $event->getFileId();
		$metadata = $event->getMetadata();
		if ($object === null || $fileId === null
			|| ($metadata[GeneratedDocumentFiler::FILED_BY_CALLER] ?? false) === true
			|| $this->filer->wantsFiling(metadata: $metadata) === false
			|| $this->cases->isCase(register: $object['register'], schema: $object['schema']) === false
		) {
			return;
		}

		$document = $event->getDocument();
		$title = trim((string)($event->getTemplate()['name'] ?? ''));
		if ($title === '') {
			$title = (string)($document['name'] ?? 'Document');
		}

		try {
			$this->filer->file(
				caseId: $object['id'],
				fileId: $fileId,
				title: $title,
				mime: (string)($document['mime'] ?? 'application/octet-stream'),
				metadata: $metadata
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a document Filinq generated for a case could not be filed in its dossier',
				['case' => $object['id'], 'file' => $fileId, 'requestingApp' => $event->getRequestingApp(), 'error' => $e->getMessage()]
			);

			throw new RuntimeException('The generated document could not be filed on the case: ' . $e->getMessage(), 0, $e);
		}
	}//end handle()
}//end class
