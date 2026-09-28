<?php

/**
 * Filinq DocumentGenerationRequestedEvent test stub.
 *
 * A verbatim copy of filinq's class (lib/Event/DocumentGenerationRequestedEvent.php, development after
 * filinq#1224, read 2026-09-28), so the dossiq code that uses it can be tested
 * and analysed without filinq installed. tests/bootstrap.php loads it only when
 * the real class is absent. If filinq changes the class, change this copy with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Filinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app request event: an app asks Filinq to generate a document.
 *
 * The request is immutable (constructor-injected). The result slots are
 * written by Filinq's listener and read by the dispatcher after dispatch.
 *
 * @category Event
 * @package  OCA\Filinq\Event
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.filinq.app
 *
 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
 */
class DocumentGenerationRequestedEvent extends Event {

	/**
	 * The generated document (result slot).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why generation was refused or failed (result slot).
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Construct the request event.
	 *
	 * @param array<string, mixed> $request       The generation request. The same keys the
	 *                                            `filinq.generate-document` flow node takes
	 *                                            (templateId, templateSlug + templateNamespace,
	 *                                            or template; format, storeFile, targetField,
	 *                                            targetPath, filename, huisstijlId, metadata),
	 *                                            plus `data` (the render context) and `object`
	 *                                            ({register, schema, id}) the document belongs to.
	 * @param string               $requestingApp The app id asking, echoed on DocumentGeneratedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function __construct(
		private readonly array $request,
		private readonly string $requestingApp,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the generation request.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function getRequest(): array {
		return $this->request;
	}//end getRequest()

	/**
	 * Get the app that asked.
	 *
	 * @return string The requesting app id.
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function getRequestingApp(): string {
		return $this->requestingApp;
	}//end getRequestingApp()

	/**
	 * Record the generated document (written by Filinq's listener).
	 *
	 * @param array<string, mixed> $result The document: fileId, path, name, mime, size,
	 *                                     format, template, object, targetField, metadata,
	 *                                     requestingApp, warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function setResult(array $result): void {
		$this->result = $result;
		$this->error = null;

	}//end setResult()

	/**
	 * Get the generated document.
	 *
	 * @return array<string, mixed>|null Null until Filinq has generated it.
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Whether Filinq generated the document.
	 *
	 * @return bool True once a result is set.
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()

	/**
	 * Record why generation did not happen (written by Filinq's listener).
	 *
	 * @param string $error The reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function setError(string $error): void {
		$this->error = $error;
		$this->result = null;

	}//end setError()

	/**
	 * Get why generation did not happen.
	 *
	 * @return string|null Null when it succeeded or nobody handled the event.
	 *
	 * @spec openspec/changes/flow-generate-document-node/specs/flow-document-generation/spec.md#requirement-other-apps-request-a-document-through-a-command-event
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
