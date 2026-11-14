<?php

/**
 * Contract of IntakeLog
 *
 * The public surface of IntakeLog that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of IntakeLog.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeLogInterface {

	/**
	 * The outcome of an entry that is waiting for a person.
	 */
	public const OUTCOME_QUARANTINED = 'quarantined';

	/**
	 * The outcome of an entry a person released into a case.
	 */
	public const OUTCOME_RELEASED = 'released';

	/**
	 * The outcome of an entry that became a case.
	 */
	public const OUTCOME_CASE = 'case';

	/**
	 * The outcome of an entry nothing could place.
	 */
	public const OUTCOME_INBOX = 'inbox';

	/**
	 * The outcome of an entry that was sent on to another body.
	 */
	public const OUTCOME_FORWARDED = 'forwarded';

	/**
	 * The outcome of an entry that was filed in another folder.
	 */
	public const OUTCOME_MOVED = 'moved';

	/**
	 * Write a few fields onto an entry that already exists.
	 *
	 * @param string               $entryId The entry.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return boolean True when the write landed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function amend(string $entryId, array $changes): bool;

	/**
	 * One entry.
	 *
	 * @param string $entryId The entry.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function find(string $entryId): ?array;

	/**
	 * The entry this channel already holds for this message, when there is one.
	 *
	 * 🔴 BARE KEYS, NOT `filter[channel]`. OpenRegister's objects endpoint
	 * reads a bare property name and answers the EMPTY SET for a `filter[...]`
	 * one, with no error either way (openregister#3611). An empty set here
	 * reads as "this message is new", so the wrong spelling would open a
	 * second case on every duplicate and report success both times.
	 *
	 * @param string $channel          The channel id.
	 * @param string $channelMessageId The channel's own id for the message.
	 *
	 * @return array<string, mixed>|null The entry, or null when this message is new.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function findChannelEntry(string $channel, string $channelMessageId): ?array;

	/**
	 * Record a message that arrived on a channel rather than in the mailbox.
	 *
	 * 🔴 THIS IS THE SURFACE, AND IT IS ALSO THE DUPLICATE CHECK. integriq's
	 * channel adapters hand a message over as `IntakeMessageRoutedEvent`, and
	 * whatever dossiq decides has to be readable by the intake worker who
	 * already reads this page. Writing only to `nextcloud.log` would put the
	 * answer where the person who has to give it cannot reach, which is the
	 * failure this log was built to end. {@see ChannelIntake} then reads its
	 * own entries back to recognise a second delivery.
	 *
	 * 🔴 NO TIMELINE ENTRY, DELIBERATELY. {@see self::recordOnTimeline()}
	 * writes `TimelineKinds::MAIL_IN`, and a Teams message is not an incoming
	 * e-mail. The kinds are DECLARED with their required properties and
	 * provisioned once, so a channel kind is a declaration and a migration
	 * rather than a constant, and it is a change of its own. Until it lands
	 * the case carries `intakeChannel` and the message lives here.
	 *
	 * The mail-shaped columns stay empty on purpose: an entry with no
	 * `mailMessageId` and a `channel` is how a reader tells the two kinds of
	 * entry apart without a second schema.
	 *
	 * @param string $channel          The channel id, as integriq's adapter names it.
	 * @param string $channelMessageId The channel's own id for the message.
	 * @param string $sender           The correspondent, as the channel gave it.
	 * @param string $subject          A one-line description of the message.
	 * @param string $outcome          What became of it, one of the OUTCOME_ values.
	 * @param string $reason           Why, in a sentence a handler can read.
	 * @param string $caseId           The case it became, or ''.
	 *
	 * @return string The entry id, or '' when nothing was stored.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function recordChannelMessage(
		string $channel,
		string $channelMessageId,
		string $sender,
		string $subject,
		string $outcome,
		string $reason,
		string $caseId = '',
	): string;

	/**
	 * The entries matching a search.
	 *
	 * @param array<string, mixed> $filters Object-field filters, plus `_limit`.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function search(array $filters = []): array;
}//end interface
