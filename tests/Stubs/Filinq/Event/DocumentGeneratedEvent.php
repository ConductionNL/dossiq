<?php

/**
 * Filinq DocumentGeneratedEvent test stub.
 *
 * A verbatim copy of filinq's class (lib/Event/DocumentGeneratedEvent.php, development after
 * filinq#1224, read 2026-09-28), so the dossiq code that uses it can be tested
 * and analysed without filinq installed. tests/bootstrap.php loads it only when
 * the real class is absent.
 * If filinq changes the class, change this copy with it. Its spec links
 * point into filinq's repository and are left out here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Filinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Announces a document Filinq generated.
 *
 * @category Event
 * @package  OCA\Filinq\Event
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.filinq.app
 *
 */
class DocumentGeneratedEvent extends Event {

	/**
	 * Construct the event.
	 *
	 * @param array<string, mixed> $document      The generated document: fileId, path, name,
	 *                                            mime, size, format, template, object,
	 *                                            targetField, metadata, warnings.
	 * @param string               $requestingApp The app that asked for it.
	 *
	 * @return void
	 *
	 */
	public function __construct(
		private readonly array $document,
		private readonly string $requestingApp,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The whole generated-document record.
	 *
	 * @return array<string, mixed> The document.
	 *
	 */
	public function getDocument(): array {
		return $this->document;
	}//end getDocument()

	/**
	 * The object the document belongs to.
	 *
	 * @return array{register: string, schema: string, id: string}|null Null when the request named none.
	 *
	 */
	public function getObject(): ?array {
		$object = ($this->document['object'] ?? null);
		if (is_array($object) === false) {
			return null;
		}

		return [
			'register' => (string)($object['register'] ?? ''),
			'schema' => (string)($object['schema'] ?? ''),
			'id' => (string)($object['id'] ?? ''),
		];
	}//end getObject()

	/**
	 * The Nextcloud file id of the stored document.
	 *
	 * @return int|null Null when no file was stored (a field-only generation).
	 *
	 */
	public function getFileId(): ?int {
		$fileId = ($this->document['fileId'] ?? null);
		if ($fileId === null) {
			return null;
		}

		return (int)$fileId;
	}//end getFileId()

	/**
	 * The path of the stored document.
	 *
	 * @return string|null Null when no file was stored.
	 *
	 */
	public function getFilePath(): ?string {
		$path = ($this->document['path'] ?? null);
		if ($path === null) {
			return null;
		}

		return (string)$path;
	}//end getFilePath()

	/**
	 * The template the document was rendered from.
	 *
	 * @return array<string, mixed> id, slug, name and source (id, slug or inline).
	 *
	 */
	public function getTemplate(): array {
		return (array)($this->document['template'] ?? []);
	}//end getTemplate()

	/**
	 * The requester's metadata, passed through untouched.
	 *
	 * @return array<string, mixed> The metadata.
	 *
	 */
	public function getMetadata(): array {
		return (array)($this->document['metadata'] ?? []);
	}//end getMetadata()

	/**
	 * The app that asked for the document.
	 *
	 * @return string The requesting app id.
	 *
	 */
	public function getRequestingApp(): string {
		return $this->requestingApp;
	}//end getRequestingApp()
}//end class
