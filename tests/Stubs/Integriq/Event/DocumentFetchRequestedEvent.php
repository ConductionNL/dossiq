<?php

/**
 * Integriq DocumentFetchRequested Event.
 *
 * TEST STUB: the contract integriq's open change connectors-graph-document-search
 * names in its design D3 (integriq development, 2026-09-28). integriq has not
 * shipped the class yet, so this is the shape dossiq calls, written from that
 * design and asked of integriq in for-ruben/dossiq-sibling-asks.md. Loaded by
 * tests/bootstrap.php only when integriq is absent. Re-copy it from integriq
 * once the class lands there.
 *
 * A sibling app asks integriq for the content of one search hit by its
 * handle. integriq answers `{fileName, mimeType, content}` and keeps no copy.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec exclude contract stub of the owning app's class; the original tag points into that app's repo
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "give me the content behind this handle".
 *
 * @spec exclude contract stub of the owning app's class; the original tag points into that app's repo
 */
class DocumentFetchRequestedEvent extends Event {

	/**
	 * Whether integriq answered.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The answer: `{fileName, mimeType, content}`.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp     The asking app id.
	 * @param string $connectionKey The app's linked connection.
	 * @param string $userId        The person the fetch runs for.
	 * @param string $handle        The hit's fetch handle (`remoteId`).
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $connectionKey,
		private readonly string $userId,
		private readonly string $handle,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app id.
	 *
	 * @return string The app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The linked connection.
	 *
	 * @return string The connection key.
	 */
	public function getConnectionKey(): string {
		return $this->connectionKey;
	}//end getConnectionKey()

	/**
	 * The person the fetch runs for.
	 *
	 * @return string The user id.
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * The fetch handle.
	 *
	 * @return string The handle.
	 */
	public function getHandle(): string {
		return $this->handle;
	}//end getHandle()

	/**
	 * Record the answer and mark the event handled.
	 *
	 * @param array<string,mixed> $result `{fileName, mimeType, content}`.
	 *
	 * @return void
	 */
	public function setResult(array $result): void {
		$this->result = $result;
		$this->handled = true;
	}//end setResult()

	/**
	 * Whether integriq answered.
	 *
	 * @return bool True when handled.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The answer, once handled.
	 *
	 * @return array<string,mixed>|null The answer.
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()
}//end class
