<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Bezwaar
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Ask the decision app to hold a bezwaaradviescommissie as a GovernanceBody.
 *
 * A bezwaaradviescommissie IS a governance body, and governance bodies belong
 * to the decision app. Dossiq carrying a parallel committee schema is the
 * duplication that shows up as drift: two places to add a field, two to fix a
 * bug, and no shared view of who sits on what.
 *
 * 🔴 THE COMMAND TRAVELS AS A TYPED EVENT, not over REST. ADR-041 says so and
 * gate-27 enforces it, but there is a second, harder reason: the decision app's
 * REST write path refuses a request with no signed-in user, and an in-process
 * call to our own instance has none. The REST seam is the door for EXTERNAL
 * callers; this is the door for us.
 *
 * The shape is {@see \OCA\Dossiq\Service\ContractDecisionDelegationService}'s,
 * deliberately: same class_exists guard, same positional construction through a
 * class-string, same fail-closed reading of the result slots.
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */
class CommitteeDelegationService {

	/**
	 * Every spelling of the governance-body command event FQN, newest first.
	 *
	 * TWO SPELLINGS for the same reason the decision delegation carries two: the
	 * decision app renamed its namespace from OCA\Decidesk to OCA\Decidiq with
	 * no compatibility alias, and a constant naming only one spelling reports
	 * "not installed" on an instance where it is installed.
	 *
	 * @var array<int, string>
	 */
	private const GOVERNANCE_BODY_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\GovernanceBodyRequestedEvent',
		'\\OCA\\Decidesk\\Event\\GovernanceBodyRequestedEvent',
	];

	/**
	 * This app's id AS THE DECISION APP KNOWS IT.
	 *
	 * FROZEN, like the decision delegation's. It is half of the key the other
	 * side resolves a repeat command on, so changing it does not rename a
	 * mapping — it orphans every body already raised and mints a duplicate set
	 * on the next run. It moves only in a coordinated pass on both sides.
	 *
	 * @var string
	 */
	private const SOURCE_APP = 'dossiq';

	/**
	 * The GovernanceBody.bodyType a bezwaaradviescommissie maps onto.
	 *
	 * @var string
	 */
	private const BODY_TYPE = 'advisory-body';

	/**
	 * Awb article a bezwaaradviescommissie is constituted under.
	 *
	 * @var string
	 */
	private const STATUTORY_BASIS = 'Awb 7:13';

	/**
	 * Local committee fields that map onto GovernanceBody attributes as-is.
	 *
	 * @var array<string, string>
	 */
	private const ATTRIBUTE_MAP = [
		'quorum' => 'quorum',
		'jurisdiction' => 'jurisdiction',
		'termStartsOn' => 'termStart',
		'termEndsOn' => 'termEnd',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface  $logger          Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the decision app is installed and exposes the command seam.
	 *
	 * @return boolean True when a command can be dispatched.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function isAvailable(): bool {
		return ($this->resolveEventClass() !== null);

	}//end isAvailable()

	/**
	 * Cause a GovernanceBody for this committee to exist, and return its id.
	 *
	 * Safe to call repeatedly: the other side resolves on
	 * (sourceApp, externalReference) before writing, so a second call updates
	 * the same body rather than minting another.
	 *
	 * @param array<string, mixed> $committee A local bezwaaradviescommissie row.
	 * @param string               $actorId   The acting Nextcloud UID.
	 *
	 * @return string The GovernanceBody id.
	 *
	 * @throws RuntimeException When the decision app is absent or refused.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function ensureGovernanceBody(array $committee, string $actorId = ''): string {
		$eventClass = $this->resolveEventClass();
		if ($eventClass === null) {
			// Fail closed. Carrying on would let a bezwaar be referred to a
			// committee that exists in no shared register, which is the drift
			// this migration exists to end.
			throw new RuntimeException(
				'Committee service unavailable: the decision app is not installed, so no governance body can be raised.'
			);
		}

		$reference = $this->referenceOf(committee: $committee);
		if ($reference === '') {
			throw new RuntimeException('A committee needs an id before it can be raised as a governance body');
		}

		$name = trim((string)($committee['name'] ?? ''));
		if ($name === '') {
			throw new RuntimeException('A committee needs a name before it can be raised as a governance body');
		}

		// `active` is read straight off the committee and never defaulted here.
		// AdvisoryCommitteeService throws "Committee is archived and cannot
		// accept new bezwaaren" on it, so a silent true would start routing
		// objections to a disbanded committee with nothing erroring. The other
		// side refuses an absent value for the same reason.
		$active = ($committee['active'] ?? null);
		if (is_bool($active) === false) {
			throw new RuntimeException('A committee must state `active` as a boolean before it can be raised');
		}

		try {
			// Positional ctor args (decision-app contract): sourceApp,
			// externalReference, name, bodyType, domain, active, attributes,
			// members, actorId, correlationId.
			$event = new $eventClass(
				self::SOURCE_APP,
				$reference,
				$name,
				self::BODY_TYPE,
				$this->domainOf(committee: $committee),
				$active,
				$this->attributesOf(committee: $committee),
				$this->rosterOf(committee: $committee),
				$actorId,
				$reference
			);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq committees: GovernanceBodyRequestedEvent dispatch failed',
				['externalReference' => $reference, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Committee service error: ' . $e->getMessage(), 0, $e);
		}//end try

		$handled = (bool)$event->isHandled();
		$bodyId = (string)$event->getGovernanceBodyId();
		if ($handled === false || $bodyId === '') {
			$this->logger->error(
				'Dossiq committees: the decision app did not handle the command; failing closed',
				['externalReference' => $reference, 'handled' => $handled]
			);
			throw new RuntimeException(
				'Committee service unavailable: the decision app did not handle the governance-body command.'
			);
		}

		$this->logger->info(
			'Dossiq committees: governance body resolved',
			[
				'externalReference' => $reference,
				'governanceBodyId' => $bodyId,
				'created' => $event->isCreated(),
			]
		);

		return $bodyId;

	}//end ensureGovernanceBody()

	/**
	 * The committee's own id, which becomes the external reference.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return string The id, or an empty string.
	 */
	private function referenceOf(array $committee): string {
		return (string)($committee['id'] ?? ($committee['@self']['id'] ?? ''));

	}//end referenceOf()

	/**
	 * Map the committee's domain onto the governance-body vocabulary.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return string The domain.
	 */
	private function domainOf(array $committee): string {
		$domain = trim((string)($committee['domain'] ?? ''));
		if ($domain === '') {
			return 'general';
		}

		return $domain;

	}//end domainOf()

	/**
	 * Build the attribute bag from the fields that map straight across.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	private function attributesOf(array $committee): array {
		$attributes = ['statutoryBasis' => self::STATUTORY_BASIS];

		foreach (self::ATTRIBUTE_MAP as $local => $remote) {
			$value = ($committee[$local] ?? null);
			if ($value !== null && $value !== '') {
				$attributes[$remote] = $value;
			}
		}

		return $attributes;

	}//end attributesOf()

	/**
	 * Flatten chair, secretary and members into one roster.
	 *
	 * Awb 7:13(1) puts a chair and at least two members on the committee;
	 * 7:13(2) puts a civil servant in the secretary's seat, who sits from
	 * outside the administrative organ deciding the objection — which is what
	 * `external` records, and why the secretary carries it and the members do
	 * not.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return array<int, array<string, mixed>> The roster.
	 */
	private function rosterOf(array $committee): array {
		$roster = [];
		$seen = [];

		// Officers first, and that ORDER is the whole point. A chair who also
		// appears in members[] must keep the chair seat: the other side keys a
		// seat on (person, body), so a later `member` entry for the same uid
		// overwrites the earlier one and the committee silently loses its
		// chair. Seating officers first and skipping seen uids makes the
		// officer entry the one that survives.
		$entries = array_merge(
			$this->officers(committee: $committee),
			$this->plainMembers(committee: $committee)
		);

		foreach ($entries as $entry) {
			$uid = $entry['uid'];
			if (isset($seen[$uid]) === true) {
				continue;
			}

			$seen[$uid] = true;
			$roster[] = $entry;
		}

		return $roster;

	}//end rosterOf()


	/**
	 * The chair and secretary seats, when the committee names them.
	 *
	 * Awb 7:13(2) puts the secretary outside the administrative organ deciding
	 * the objection, which is what `external` records — and why the secretary
	 * carries it and the chair does not.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return array<int, array<string, mixed>> The officer entries.
	 */
	private function officers(array $committee): array {
		$entries = [];

		$chair = trim((string)($committee['chair'] ?? ''));
		if ($chair !== '') {
			$entries[] = ['uid' => $chair, 'role' => 'chair'];
		}

		$secretary = trim((string)($committee['secretary'] ?? ''));
		if ($secretary !== '') {
			$entries[] = [
				'uid' => $secretary,
				'role' => 'secretary',
				'external' => true,
			];
		}

		return $entries;

	}//end officers()


	/**
	 * Normalise `members[]` into roster entries, dropping the unusable.
	 *
	 * Each entry may be a bare uid string or a map carrying an `external` flag,
	 * because the schema has held both shapes.
	 *
	 * @param array<string, mixed> $committee The committee row.
	 *
	 * @return array<int, array<string, mixed>> The member entries.
	 */
	private function plainMembers(array $committee): array {
		$members = ($committee['members'] ?? []);
		if (is_array($members) === false) {
			return [];
		}

		$entries = [];
		foreach ($members as $member) {
			$entry = $this->memberEntry(member: $member);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;

	}//end plainMembers()


	/**
	 * One `members[]` element as a roster entry, or null when unusable.
	 *
	 * @param mixed $member The raw element.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	private function memberEntry(mixed $member): ?array {
		$raw = $member;
		if (is_array($member) === true) {
			$raw = ($member['uid'] ?? '');
		}

		$uid = trim((string)$raw);
		if ($uid === '') {
			return null;
		}

		$entry = ['uid' => $uid, 'role' => 'member'];
		if (is_array($member) === true && ($member['external'] ?? null) !== null) {
			$entry['external'] = (bool)$member['external'];
		}

		return $entry;

	}//end memberEntry()

	/**
	 * The first command-event class that actually exists.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveEventClass(): ?string {
		foreach (self::GOVERNANCE_BODY_REQUESTED_EVENTS as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;

	}//end resolveEventClass()

}//end class
