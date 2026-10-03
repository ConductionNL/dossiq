<?php

/**
 * Dossiq Move Action
 *
 * Filing a message in another folder of the same account, through Nextcloud
 * Mail, and recording that we did (design D-4).
 *
 * A move is a MAILBOX act, not a case act. It does not create a case, it does
 * not close one, and it does not tell the sender anything. It is what a handler
 * does to hand a case's correspondence to another team's folder, and it is
 * separate from {@see BounceAction} because sending a document to another
 * administrative body and tidying a folder are different things with different
 * legal weight.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use Psr\Log\LoggerInterface;

/**
 * Files a message in another folder, and records it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class MoveAction {

	/**
	 * The name the intake log records against a move.
	 */
	public const NAME = 'move';

	/**
	 * Constructor.
	 *
	 * @param MailGatewayInterface $gateway The mail gateway.
	 * @param IntakeLog            $log     The intake log.
	 * @param LoggerInterface      $logger  Logger.
	 */
	public function __construct(
		private readonly MailGatewayInterface $gateway,
		private readonly IntakeLog $log,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File one message in another folder of the same account.
	 *
	 * @param InboundMessage        $message The message.
	 * @param string                $target  The folder it goes to.
	 * @param string                $note    Why, as the handler wrote it.
	 * @param string                $actorId Who moved it, or '' for the pipeline.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return array{moved: bool, entryId: string} What happened, and the log entry.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function move(
		InboundMessage $message,
		string $target,
		string $note = '',
		string $actorId = '',
		array $results = [],
	): array {
		$moved = $this->gateway->moveMessage(
			$message->accountId,
			$message->mailbox,
			$message->uid,
			$target
		);
		if ($moved === false) {
			$this->logger->warning(
				'Dossiq: a message could not be filed in {target}',
				['target' => $target, 'messageId' => $message->messageId]
			);
		}

		$recorded = $results;
		if ($recorded === []) {
			$recorded = AuthenticationVerdict::unknown();
		}

		$entryId = $this->log->record(
			message: $message,
			verdict: FilterVerdict::accept(filterName: self::NAME, reason: $note),
			results: $recorded,
			outcome: IntakeLog::OUTCOME_MOVED,
			reason: $this->recordedReason(target: $target, note: $note, actorId: $actorId, moved: $moved)
		);

		if ($entryId !== '') {
			$this->log->amend(
				entryId: $entryId,
				changes: [
					'movedTo' => $target,
					'movedBy' => $actorId,
					'mailbox' => $target,
				]
			);
		}

		return ['moved' => $moved, 'entryId' => $entryId];
	}//end move()

	/**
	 * What the log says about this move.
	 *
	 * @param string  $target  The folder.
	 * @param string  $note    The handler's note.
	 * @param string  $actorId Who moved it.
	 * @param boolean $moved   Whether the mail server confirmed it.
	 *
	 * @return string The recorded reason.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function recordedReason(string $target, string $note, string $actorId, bool $moved): string {
		$who = 'the intake pipeline';
		if ($actorId !== '') {
			$who = $actorId;
		}

		if ($moved === false) {
			return 'A move to ' . $target . ' was asked for by ' . $who
				. ' and the mail server did not confirm it. Note: ' . $note;
		}

		return 'Filed in ' . $target . ' by ' . $who . '. Note: ' . $note;
	}//end recordedReason()
}//end class
