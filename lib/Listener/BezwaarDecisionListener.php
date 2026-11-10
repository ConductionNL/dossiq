<?php

/**
 * Dossiq Bezwaar Decision Listener.
 *
 * Observes OpenRegister object events on the `bezwaar` schema and
 * blocks a transition into status "Decision on objection" when no
 * published bezwaarDecision exists for the case. The listener is a
 * pure guard: when the precondition is satisfied it is a no-op; when
 * the precondition fails it reverts the status to the previous value
 * via OpenRegister (no bespoke status-machine logic — the
 * status-transition-engine remains the single owner of transition
 * mechanics, this guard just enforces the published-decision
 * requirement).
 *
 * Failures of the guard derivation itself are swallowed (logged) so
 * that bezwaar persistence is never blocked by a defective lookup.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Dossiq\Listener\Support\ObjectUpdatePayload;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards bezwaar transitions into "Decision on objection" by requiring
 * a published bezwaarDecision to exist for the bezwaar.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class BezwaarDecisionListener implements IEventListener {

	use ObjectUpdatePayload;
	use SearchesObjects;

	/**
	 * Target status the guard protects.
	 */
	private const PROTECTED_STATUS = 'Decision on objection';

	/**
	 * Upper bound on the decision rows the guard will pull per bezwaar.
	 *
	 * The probe answers a yes/no question ("does a decided bezwaarDecision
	 * exist?"), so it never needs the whole set; the bound keeps a write-path
	 * lookup cheap and bounded.
	 */
	private const DECISION_PROBE_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param LoggerInterface $logger Logger.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a findAll() row, entity or array, as an array.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
	) {
	}//end __construct()

	/**
	 * Handle a bezwaar update event.
	 *
	 * @param Event $event Event instance.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — this listener is a transition
	 * guard, not follow-up work. Its whole job is to undo a status change that
	 * should not have been persisted, so it has to run inside the write that
	 * made it. Deferring the revert would publish an invalid
	 * "Decision on objection" state to every reader, and to the notification
	 * and audit listeners that fire off the same write, for as long as the
	 * queue took to drain. The revert is a single bounded saveObject() on the
	 * object already being written.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		try {
			$object = $this->updatedObject(event: $event);
			if ($object === null) {
				return;
			}

			if ($this->isObjectionSchema(object: $object) === false) {
				return;
			}

			$status = (string)($object['status'] ?? '');
			if ($status !== self::PROTECTED_STATUS) {
				return;
			}

			if ($this->hasPublishedDecision(objection: $object) === true) {
				return;
			}

			// Guard violated — revert.
			$this->revertStatus(objection: $object, event: $event);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq bezwaar-decision: guard derivation swallowed '
				. 'exception: ' . $e->getMessage()
			);
		}//end try
	}//end handle()

	/**
	 * Decide whether the bezwaar has at least one published
	 * bezwaarDecision.
	 *
	 * @param array<string, mixed> $objection The bezwaar payload.
	 *
	 * @return bool
	 */
	private function hasPublishedDecision(array $objection): bool {
		$probe = $this->objectionObject(objection: $objection, schemaKey: 'bezwaar_decision_schema');
		if ($probe === null) {
			return true;
		}

		try {
			$all = $this->findDecisions(probe: $probe);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq bezwaar-decision: lookup failed — allowing '
				. 'transition by default: ' . $e->getMessage()
			);
			return true;
		}

		if (is_array($all) === false) {
			return false;
		}

		if ($this->containsDecidedDecision(decisions: $all) === true) {
			return true;
		}

		return $this->probeWasCutShort(decisions: $all, objectionId: $probe->objectionId);
	}//end hasPublishedDecision()

	/**
	 * Where the guard reads or writes this objection, or null when it cannot.
	 *
	 * Null without an object service, a register, the schema or the
	 * objection's id; the guard then leaves the save alone.
	 *
	 * @param array<string, mixed> $objection The objection payload.
	 * @param string               $schemaKey The config key of the schema read or written.
	 *
	 * @return ObjectionObject|null
	 */
	private function objectionObject(array $objection, string $schemaKey): ?ObjectionObject {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$target = new ObjectionObject();
		$target->objectService = $objectService;
		$target->register = $this->settingsService->getConfigValue(key: 'register');
		$target->schema = $this->settingsService->getConfigValue(key: $schemaKey);
		$target->objectionId = (string)($objection['id'] ?? ($objection['uuid'] ?? ''));
		if ($target->isComplete() === false) {
			return null;
		}

		return $target;
	}//end objectionObject()

	/**
	 * The decisions on this objection, at most DECISION_PROBE_LIMIT of them.
	 *
	 * A bezwaarDecision is "decided" either when it carries the legacy
	 * local `status:published` (historical records) OR when it has been
	 * delegated to decidesk and carries a `decisionRef` (the besluit is the
	 * decidesk outcome — dossiq-delegate-remaining-decisions-to-decidesk,
	 * REQ-PDRD-001/REQ-PDRD-003). Both satisfy the published-decision guard.
	 * OpenRegister's ObjectService::findAll() takes ONE config array. This
	 * call used to pass ($register, $decisionSchema, $filters)
	 * positionally, which is a TypeError against `array $config` — and the
	 * catch around this call turned that TypeError into "allow by default", so this
	 * guard has never once blocked a transition.
	 *
	 * BOUNDED on purpose. This runs on the write path of every bezwaar
	 * update, so an unbounded findAll() would list and materialise every
	 * decision row on the register on each save — the
	 * OpenRegisterFlowResolver failure mode. A bezwaar carries one
	 * decision, occasionally a handful; self::DECISION_PROBE_LIMIT is two
	 * orders of magnitude of headroom.
	 *
	 * @param ObjectionObject $probe The objection and the decision schema.
	 *
	 * @return mixed The rows the object service answers.
	 */
	private function findDecisions(ObjectionObject $probe): mixed {
		return $probe->objectService->findAll(
			[
				'filters' => [
					'register' => $probe->register,
					'schema' => $probe->schema,
					'objectionProceeding' => $probe->objectionId,
				],
				'limit' => self::DECISION_PROBE_LIMIT,
			]
		);
	}//end findDecisions()

	/**
	 * Whether a page without a decided decision was cut short by the bound.
	 *
	 * @param array<int, mixed> $decisions   The probed decision rows.
	 * @param string            $objectionId The objection.
	 *
	 * @return bool True when the probe hit its bound, so the guard fails open.
	 */
	private function probeWasCutShort(array $decisions, string $objectionId): bool {
		// A FULL page means the bound, not the data, ended the scan: a decided
		// decision may sit past it. Reverting here would block a legitimate
		// transition on a bezwaar with an improbable number of decisions, so
		// the guard fails open — the same policy the rest of this listener
		// applies to a lookup it cannot complete.
		if (count($decisions) >= self::DECISION_PROBE_LIMIT) {
			$this->logger->warning(
				'Dossiq bezwaar-decision: decision probe hit its bound of '
				. self::DECISION_PROBE_LIMIT . ' rows — allowing the transition '
				. 'rather than reverting on an incomplete scan',
				['bezwaarId' => $objectionId]
			);
			return true;
		}

		return false;
	}//end probeWasCutShort()

	/**
	 * Scan bezwaarDecision rows for one that counts as decided.
	 *
	 * `findAll()` returns `ObjectEntity` objects, not arrays. This scan used to
	 * skip every row that was not an array, so it skipped all of them: no
	 * decision ever counted as decided, and every bezwaar entering
	 * "Decision on objection" was reverted, including one decidesk had just
	 * decided. Each row is now read through {@see OpenRegisterRowNormaliser}.
	 * A row it cannot read comes back empty, carries neither marker, and so
	 * never counts as decided.
	 *
	 * @param array<int, mixed> $decisions The bezwaarDecision rows as findAll() returned them.
	 *
	 * @return bool
	 */
	private function containsDecidedDecision(array $decisions): bool {
		foreach ($decisions as $row) {
			$decision = $this->rowNormaliser->normalise(row: $row)['data'];

			$status = (string)($decision['status'] ?? '');
			$decisionRef = (string)($decision['decisionRef'] ?? '');
			if ($status === 'published' || $decisionRef !== '') {
				return true;
			}
		}

		return false;
	}//end containsDecidedDecision()

	/**
	 * Revert the bezwaar's status by reading the previous status from
	 * the event and writing it back via OpenRegister. The
	 * status-transition-engine remains the sole owner of forward
	 * transitions; this listener only reverts disallowed jumps.
	 *
	 * @param array<string, mixed> $objection Current bezwaar payload.
	 * @param Event $event Event instance.
	 *
	 * @return void
	 */
	private function revertStatus(array $objection, Event $event): void {
		$previous = $this->previousStatus(event: $event);
		if ($previous === '') {
			return;
		}

		$target = $this->objectionObject(objection: $objection, schemaKey: 'bezwaar_schema');
		if ($target === null) {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $target->objectService,
				register: $target->register,
				schema: $target->schema,
				id: $target->objectionId,
				changes: ['status' => $previous]
			);
			$this->logger->warning(
				'Dossiq bezwaar-decision: blocked transition into "'
				. self::PROTECTED_STATUS . '" — no published bezwaarDecision; '
				. 'reverted to "' . $previous . '"',
				['bezwaarId' => $target->objectionId]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar-decision: revert failed: ' . $e->getMessage()
			);
		}
	}//end revertStatus()

	/**
	 * Whether the object belongs to the `bezwaar` schema.
	 *
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return bool
	 */
	private function isObjectionSchema(array $object): bool {
		return $this->matchesConfiguredSchema(candidate: $this->schemaNamedAnywhere(object: $object), schemaKey: 'bezwaar_schema');
	}//end isObjectionSchema()
}//end class
