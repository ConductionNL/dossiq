<?php

/**
 * Integriq DocumentSearchRequested Event.
 *
 * TEST STUB: the contract integriq's open change connectors-graph-document-search
 * names in its design D3 (integriq development, 2026-09-28). integriq has not
 * shipped the class yet, so this is the shape dossiq calls, written from that
 * design and asked of integriq in for-ruben/dossiq-sibling-asks.md. Loaded by
 * tests/bootstrap.php only when integriq is absent. Re-copy it from integriq
 * once the class lands there.
 *
 * A sibling app asks integriq to search a linked Microsoft 365 connection for
 * terms in a period, for a named person. integriq answers synchronously in
 * the result slot: `{hits, moreCount, notices}`, each hit a REQ-DCC-004
 * envelope with `entityType` and `remoteId` as the fetch handle.
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
 * Typed cross-app question: "search this connection for these terms".
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 event contract is a flat
 * readonly envelope the consumer stubs mirror verbatim.
 *
 * @spec exclude contract stub of the owning app's class; the original tag points into that app's repo
 */
class DocumentSearchRequestedEvent extends Event {

	/**
	 * Whether integriq answered.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The answer: `{hits, moreCount, notices}`.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string            $sourceApp     The asking app id.
	 * @param string            $connectionKey The app's linked connection.
	 * @param string            $userId        The person the search runs for.
	 * @param string            $terms         The search terms.
	 * @param string|null       $from          Start of the period, Y-m-d, or null.
	 * @param string|null       $to            End of the period, Y-m-d, or null.
	 * @param array<int,string> $entityTypes   `driveItem`, `message`, `chatMessage`; empty for all.
	 * @param int               $limit         At most this many hits.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $connectionKey,
		private readonly string $userId,
		private readonly string $terms,
		private readonly ?string $from = null,
		private readonly ?string $to = null,
		private readonly array $entityTypes = [],
		private readonly int $limit = 50,
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
	 * The person the search runs for.
	 *
	 * @return string The user id.
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * The search terms.
	 *
	 * @return string The terms.
	 */
	public function getTerms(): string {
		return $this->terms;
	}//end getTerms()

	/**
	 * Start of the period.
	 *
	 * @return string|null The date.
	 */
	public function getFrom(): ?string {
		return $this->from;
	}//end getFrom()

	/**
	 * End of the period.
	 *
	 * @return string|null The date.
	 */
	public function getTo(): ?string {
		return $this->to;
	}//end getTo()

	/**
	 * The entity types to search.
	 *
	 * @return array<int,string> The types.
	 */
	public function getEntityTypes(): array {
		return $this->entityTypes;
	}//end getEntityTypes()

	/**
	 * The hit limit.
	 *
	 * @return int The limit.
	 */
	public function getLimit(): int {
		return $this->limit;
	}//end getLimit()

	/**
	 * Record the answer and mark the event handled.
	 *
	 * @param array<string,mixed> $result `{hits, moreCount, notices}`.
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
