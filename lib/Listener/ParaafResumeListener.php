<?php

/**
 * A given paraaf wakes the run that asked for it.
 *
 * `dossiq.askParaaf` records who is being asked and suspends; it deliberately
 * creates no `parafeeractie`, because a parafeeractie is the record of a
 * sign-off somebody gave and its `action` is required. So the approver signs
 * through the ordinary parafering surfaces, which create the paraaf with its
 * action, and this listener is where that closes the loop.
 *
 * It does two things the flow cannot do for itself:
 *
 * 1. STAMPS `flowRun` and `flowNode` onto the paraaf. A run holds one awaiting
 *    slot per node and cannot say which of them a signal answers, so a paraaf
 *    that named only the run could not resume it. The schema has carried both
 *    fields since dossiq#1602 for exactly this moment.
 * 2. SIGNALS the run with what the approver actually decided, so the steps
 *    after it can branch on a real answer rather than on "somebody replied".
 *
 * 🔴 IT PERFORMS THE AUTHORIZATION CHECK ITSELF, AND MUST.
 *
 * OpenRegister's HTTP resume endpoint refuses a signal from anyone but the
 * step's assignee. This path does not go through that endpoint — it calls
 * `FlowRunService::signal()` directly — so the guard is NOT inherited. A paraaf
 * is a signature; without the check here, any user who can write a
 * parafeeractie object could sign off somebody else's step, and nothing about
 * the resulting run would look wrong.
 *
 * The rule is not re-implemented: {@see FlowRunAssignee} is the same object the
 * HTTP endpoint consults, exactly as TaskCompletionResumeListener does.
 *
 * WHY IT NEVER BLOCKS THE WRITE. The paraaf is already saved by the time this
 * runs. Refusing here cannot un-sign it, so a refusal means "the paraaf stands
 * but the run is not resumed" — recorded loudly, because the alternative
 * (resuming anyway) is the hole this exists to close.
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
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resumes the flow run a newly given paraaf answers.
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 */
class ParaafResumeListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ParaafFlowLinkage    $linkage         Reads the voorstel's run, records it on the paraaf.
	 * @param FlowRunMapper        $runs            Loads the run a paraaf answers.
	 * @param FlowRunService       $runner          Delivers the resume signal.
	 * @param IUserSession         $userSession     The acting user.
	 * @param IGroupManager        $groups          Group membership, for the assignee check.
	 * @param LoggerInterface      $logger          The logger.
	 * @param FlowRunAssignee|null $assignees       The shared access rule; built when absent.
	 */
	public function __construct(
		private readonly ParaafFlowLinkage $linkage,
		private readonly FlowRunMapper $runs,
		private readonly FlowRunService $runner,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
		private readonly ?FlowRunAssignee $assignees = null,
	) {
	}//end __construct()

	/**
	 * Resume the run this paraaf answers, if it answers one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$paraaf = $this->paraafFrom(event: $event);
		if ($paraaf === null) {
			return;
		}

		$runUuid = $this->linkage->runDriving(proposalId: (string)$paraaf['proposal']);
		if ($runUuid === '') {
			// The ordinary case today: the voorstel is driven by its route
			// snapshot, not by a flow. Nothing to wake.
			return;
		}

		try {
			$run = $this->runs->findByUuid($runUuid);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: a paraaf named flow run ' . $runUuid . ', which no longer exists',
				['paraaf' => ($paraaf['id'] ?? null)],
			);
			return;
		}

		$nodeId = $this->awaitingNode(run: $run);
		if ($nodeId === '') {
			// A run with no awaiting slot is not waiting on a paraaf. Signalling
			// it would either be lost or would resurrect a finished run.
			return;
		}

		$uid = $this->userSession->getUser()?->getUID();

		$assignees = ($this->assignees ?? new FlowRunAssignee(groupManager: $this->groups));
		if ($assignees->mayAnswer(run: $run, uid: $uid) === false) {
			// 🔴 The refusal that matters. The paraaf is already saved; what is
			// withheld is the RESUME.
			$this->logger->warning(
				'Dossiq: refusing to resume flow run ' . $runUuid
					. ' — the user who gave the paraaf is not the assignee of the awaiting step',
				['paraaf' => ($paraaf['id'] ?? null), 'user' => $uid, 'node' => $nodeId],
			);
			return;
		}

		$this->linkage->stamp(paraaf: $paraaf, runUuid: $runUuid, nodeId: $nodeId);

		try {
			$this->runner->signal(
				run: $run,
				payload: [
					// The approver's own decision, not a bare "completed": the
					// steps after this one branch on WHICH way it went, and a
					// returned voorstel must not read as an approved one.
					'decision' => (string)$paraaf['action'],
					'node' => $nodeId,
					'parafeeractieId' => (string)($paraaf['id'] ?? ''),
					'mandate' => (string)($paraaf['mandate'] ?? ''),
					'onBehalfOf' => (string)($paraaf['onBehalfOf'] ?? ''),
					'comment' => (string)($paraaf['comment'] ?? ''),
					'advice' => (string)($paraaf['advice'] ?? ''),
					'signedBy' => (string)$uid,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not resume flow run ' . $runUuid . ' after a paraaf was given',
				['error' => $e->getMessage(), 'paraaf' => ($paraaf['id'] ?? null)],
			);
		}
	}//end handle()

	/**
	 * The paraaf this event created, when it is one that could answer a run.
	 *
	 * Recognised by the shape a parafeeractie is required to have — a proposal,
	 * a step, an actor and an action — rather than by schema id, which the
	 * event reports in a form that varies by write path. The narrowing that
	 * actually protects this listener is the next check: the named voorstel has
	 * to carry a flow run. Anything else either has no proposal, or names one
	 * that no flow drives.
	 *
	 * @param ObjectCreatedEvent $event The creation.
	 *
	 * @return array<string, mixed>|null The paraaf, or null.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function paraafFrom(ObjectCreatedEvent $event): ?array {
		try {
			$object = $event->getObject();
		} catch (Throwable $e) {
			return null;
		}

		// The event carries an ObjectEntity, which answers getObject(). A bare
		// array is accepted too: event shapes and test doubles have differed
		// here before, and a listener that fatals on the other shape fails a
		// write it was only observing.
		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$object = $object->getObject();
		}

		if (is_array($object) === false) {
			return null;
		}

		foreach (['proposal', 'actor', 'action'] as $field) {
			if (trim((string)($object[$field] ?? '')) === '') {
				return null;
			}
		}

		if (array_key_exists('step', $object) === false) {
			return null;
		}

		return $object;
	}//end paraafFrom()

	/**
	 * The id of the node this run is waiting on.
	 *
	 * The awaiting slot is the one that ASKED (`askedAt`) — the same rule
	 * FlowRunAssignee applies to find the assignee — and the slot's KEY is the
	 * node id, which is what a paraaf has to record to resume it.
	 *
	 * @param object $run The run.
	 *
	 * @return string The node id, or an empty string.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function awaitingNode(object $run): string {
		if (method_exists($run, 'getContext') === false) {
			return '';
		}

		$slots = ((($run->getContext() ?? [])[FlowResumeState::CONTEXT_KEY]) ?? []);
		if (is_array($slots) === false) {
			return '';
		}

		foreach ($slots as $nodeId => $slot) {
			if (is_array($slot) === true && isset($slot['askedAt']) === true) {
				return (string)$nodeId;
			}
		}

		return '';
	}//end awaitingNode()

}//end class
