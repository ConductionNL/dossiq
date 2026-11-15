<?php

/**
 * Dossiq transferred case act listener
 *
 * Names each act recorded on a case and hands it to the follow of the
 * organisation that transferred the case away, so the sender sees what the
 * receiver does.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Transfer\TransferredCaseFollow;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\Comments\CommentsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Hands each act on a case to the follow of a transfer that handed it over.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */
class TransferredCaseActListener implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The case schema's slug.
	 *
	 * @var string
	 */
	public const CASE_SCHEMA = 'case';

	/**
	 * The act a new record on a case is, by schema slug.
	 *
	 * @var array<string, string>
	 */
	public const CREATED_ACTS = [
		'caseDocument' => 'document added',
		'customerContact' => 'contact moment added',
		'contactmoment' => 'contact moment added',
	];

	/**
	 * The comment object type OpenRegister files object notes under.
	 *
	 * @var string
	 */
	public const NOTE_OBJECT_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param TransferredCaseFollow             $follow       Records the act for the sender.
	 * @param ObjectSchemaSlugResolverInterface $slugResolver Resolves a schema id to its slug.
	 * @param IUserSession|null                 $userSession  Who did it; nobody means the system.
	 */
	public function __construct(
		private readonly TransferredCaseFollow $follow,
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Record the act this event was on a case, if it was one.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md#requirement-a-transfer-hands-the-case-over-and-the-sender-follows-its-activity-req-crs-42
	 */
	public function handle(Event $event): void {
		[$caseId, $act] = $this->actOnCase(event: $event);
		if ($caseId === '' || $act === '') {
			return;
		}

		$this->follow->record(caseId: $caseId, act: $act, actor: $this->actor());
	}//end handle()

	/**
	 * The case and the act the event was, or two empty strings.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function actOnCase(Event $event): array {
		if ($event instanceof TaskTerminalEvent) {
			return [$this->completedTaskCase(event: $event), 'task completed'];
		}

		if ($event instanceof CommentsEvent) {
			return [$this->notedObject(event: $event), 'note added'];
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) {
			return $this->objectAct(event: $event);
		}

		return ['', ''];
	}//end actOnCase()

	/**
	 * The act of a saved record: a new document or contact moment on a case, or a case's status move.
	 *
	 * @param Event $event An ObjectCreatedEvent or ObjectUpdatedEvent.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function objectAct(Event $event): array {
		$after = $this->savedObject(event: $event);
		if ($after === null) {
			return ['', ''];
		}

		$slug = $this->slugResolver->resolveFromPayload(payload: $after);
		if ($event instanceof ObjectCreatedEvent) {
			$act = (self::CREATED_ACTS[$slug] ?? '');
			if ($act === '') {
				return ['', ''];
			}

			return [trim((string)($after['case'] ?? '')), $act];
		}

		if ($slug !== self::CASE_SCHEMA) {
			return ['', ''];
		}

		// An update without its before is not read as a move: every system
		// re-save would otherwise reach the sender as an act.
		$before = $this->previousObject(event: $event);
		if ($before === null || $this->unchanged(before: $before, after: $after, fields: ['status']) === true) {
			return ['', ''];
		}

		return [(string)($after['id'] ?? ''), 'status changed'];
	}//end objectAct()

	/**
	 * The object a new note was written on.
	 *
	 * @param CommentsEvent $event The comment event.
	 *
	 * @return string The object uuid, or ''.
	 */
	private function notedObject(CommentsEvent $event): string {
		try {
			$comment = $event->getComment();
			if ($event->getEvent() !== 'add' || $comment->getObjectType() !== self::NOTE_OBJECT_TYPE) {
				return '';
			}

			return trim($comment->getObjectId());
		} catch (Throwable) {
			return '';
		}
	}//end notedObject()

	/**
	 * The case a completed task was on; a withdrawn or uncommitted task is no act.
	 *
	 * @param TaskTerminalEvent $event The engine's terminal event.
	 *
	 * @return string The case uuid, or ''.
	 */
	private function completedTaskCase(TaskTerminalEvent $event): string {
		try {
			$task = $event->getTask();
			if ($event->isCommitted() === false || (string)$task->getState() !== 'completed') {
				return '';
			}

			return trim((string)($task->getObjectUuid() ?? ''));
		} catch (Throwable) {
			return '';
		}
	}//end completedTaskCase()

	/**
	 * The user who did it, or an empty string for the system.
	 *
	 * @return string
	 */
	private function actor(): string {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end actor()
}//end class
