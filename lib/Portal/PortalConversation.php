<?php

/**
 * The conversation between a resident and the handler of their case, as dossiq
 * declares it to portaliq.
 *
 * THREE PIECES OF ONE CONVERSATION, KEPT IN ONE PLACE. The inbox reply that
 * carries the case from the message answered, the reply form that offers the
 * resident's own cases instead of a uuid box, and the question a resident asks
 * from the case page. Each names the same schema (`portaalBericht`), the same
 * guard (a `crossRefs` reference to the resident's own cases) and the same
 * direction, so writing them beside each other is what keeps them from
 * drifting apart. {@see CitizenManifest} places them; it is at the length phpmd
 * refuses, which is the other reason they live here.
 *
 * THE CASE IS NEVER THE CLIENT'S TO CHOOSE FREELY. A reply from the inbox gets
 * `caseId` from the message the resident owns (`reply.carry`, which portaliq
 * resolves server-side); a question from the case page gets it from the case
 * on screen (`recordField`); a message the resident starts from the inbox page
 * offers a choice from their own cases (`optionsProviders`). Every route still
 * passes the `crossRefs` guard, so a uuid typed into a request body is refused
 * with 403 `cross_ref_refused` before anything is written.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#2-attachments
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

/**
 * Declares the reply, the reply form and the question from the case.
 *
 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#3-reply-and-case-choice
 */
class PortalConversation {

	/**
	 * The action a resident answers a message with, and starts one from the inbox page.
	 *
	 * @var string
	 */
	public const REPLY_ACTION = 'replyToMessage';

	/**
	 * The action a resident asks a question about the case on screen with.
	 *
	 * @var string
	 */
	public const ASK_ACTION = 'askAboutCase';

	/**
	 * The provider method that lists the messages about one case.
	 *
	 * @var string
	 */
	public const MESSAGES_PROVIDER = 'caseMessages';

	/**
	 * What a resident may attach to a message: the file kinds a letter, a
	 * photo or a scan arrives in. 20 MB is portaliq's own default, written out
	 * so the form says it rather than inheriting it in silence.
	 *
	 * @var array<string, mixed>
	 */
	public const ATTACHMENTS_FIELD = [
		'label' => 'Bijlagen',
		'type' => 'file',
		'multiple' => true,
		'accept' => ['.pdf', '.jpg', '.jpeg', '.png', '.doc', '.docx', '.odt'],
		'maxSizeMb' => 20,
	];

	/**
	 * The `reply` the citizen inbox declares (portaliq inbox-reply-with-attachments D2).
	 *
	 * The case is carried from the message the resident answers, and the
	 * subject is prefilled from it. portaliq keeps a carried field only when
	 * the reply action whitelists it and the inbox projects it, so `caseId` is
	 * on {@see PortalContributionProvider::CITIZEN_INBOX}'s fields too.
	 *
	 * @return array<string, mixed> The declaration.
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#3-reply-and-case-choice
	 */
	public function inboxReply(): array {
		return [
			'action' => self::REPLY_ACTION,
			'carry' => ['caseId' => 'caseId'],
			'subjectFrom' => 'subject',
		];
	}//end inboxReply()

	/**
	 * The reply form: a resident answers a message, or starts one from the inbox page.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#3-reply-and-case-choice
	 */
	public function replyAction(): array {
		return [
			'id' => self::REPLY_ACTION,
			'type' => 'create',
			// WHICH FIELD THE OPEN CASE LANDS IN when a resident presses
			// "Bericht sturen" on their case page: portaliq's cta with
			// `withRecord: true` presets this one field to the record it
			// was opened for (site-mijn-omgeving-components REQ-SMO-024).
			// It is the field `crossRefs` below already guards, so the
			// preset is checked like any typed value.
			'recordField' => 'caseId',
			'label' => 'Antwoorden',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'portaalBericht',
			// The citizen is the SENDER of a reply, so the reply is
			// scoped by who sent it. The inbox is scoped by who
			// received it, which is the same person seen from the
			// other end.
			'scopeField' => 'senderRef',
			'minTrust' => 'low',
			'fields' => ['subject', 'content', 'attachments', 'caseId'],
			'defaults' => [
				'direction' => 'citizen_to_handler',
				'senderType' => 'burger',
			],
			'requiredFields' => ['content'],
			'fieldConfigs' => [
				'subject' => ['label' => 'Onderwerp'],
				'content' => ['label' => 'Uw bericht', 'size' => 'large'],
				'attachments' => self::ATTACHMENTS_FIELD,
				'caseId' => ['label' => 'Over welke zaak gaat uw bericht?'],
			],
			// A RESIDENT PICKS A CASE BY ITS TITLE, NEVER BY ITS UUID. portaliq
			// fills this list through the resident's own scoped case
			// collection, so it can only ever offer cases they may read.
			'optionsProviders' => [
				'caseId' => $this->ownCases(),
			],
			'crossRefs' => [
				'caseId' => $this->caseGuard(),
			],
		];
	}//end replyAction()

	/**
	 * A question about the case on screen (design D-4).
	 *
	 * The same write as a reply, started from a case: `caseId` is the case
	 * the resident is looking at and is not a field they fill in.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function askAction(): array {
		return [
			'id' => self::ASK_ACTION,
			'type' => 'create',
			'recordField' => 'caseId',
			'label' => 'Stel een vraag over deze zaak',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'portaalBericht',
			'scopeField' => 'senderRef',
			'minTrust' => 'low',
			'fields' => ['subject', 'content', 'attachments', 'caseId'],
			'defaults' => [
				'direction' => 'citizen_to_handler',
				'senderType' => 'burger',
			],
			'requiredFields' => ['content'],
			'fieldConfigs' => [
				'caseId' => ['visible' => false],
				'subject' => ['label' => 'Onderwerp'],
				'content' => ['label' => 'Wat wilt u weten?', 'size' => 'large'],
				'attachments' => self::ATTACHMENTS_FIELD,
			],
			'crossRefs' => [
				'caseId' => $this->caseGuard(),
			],
		];
	}//end askAction()

	/**
	 * The messages block on the case detail (design D-4): both directions, newest first.
	 *
	 * @return array<string, string> The declaration.
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function caseMessagesBlock(): array {
		return [
			'label' => 'Berichten',
			'provider' => self::MESSAGES_PROVIDER,
		];
	}//end caseMessagesBlock()

	/**
	 * The resident's own cases as a choice, labelled by title.
	 *
	 * @return array<string, string> The options provider.
	 */
	private function ownCases(): array {
		return [
			'type' => 'collection',
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'case',
			'labelField' => 'title',
			'valueField' => 'id',
		];
	}//end ownCases()

	/**
	 * The guard that refuses a case that is not the resident's.
	 *
	 * @return array<string, mixed> The cross reference.
	 */
	private function caseGuard(): array {
		return [
			'register' => PortalContributionProvider::REGISTER,
			'schema' => 'case',
			'scopeField' => 'portalSubject',
			'required' => true,
		];
	}//end caseGuard()
}//end class
