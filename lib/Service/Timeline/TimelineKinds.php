<?php

/**
 * The timeline kinds dossiq writes, and the standard notes it seeds.
 *
 * ONE LIST, READ BY THREE PLACES: the repair step that declares the kinds,
 * the writers that name one, and the tests that check a writer names a kind
 * that was actually declared. An undeclared kind is a 400 from OpenRegister
 * rather than a plain note, deliberately, so a typo here is a refusal at the
 * first write instead of a log that quietly loses its shape.
 *
 * WHY THERE IS NO `notitie` KIND. OpenRegister projects every note written
 * through `/notes` as a timeline entry with NO kind. Declaring a `notitie`
 * kind beside that would split one log into two filter buckets meaning the
 * same thing, and a handler filtering on "Notitie" would miss every note the
 * notes tab wrote. The reader labels an entry with no kind as a note.
 *
 * THE CONTACT MOMENT'S VOCABULARY IS DOSSIQ'S OWN, NOT THE CONTRACT'S
 * EXAMPLE. OpenRegister's note for consumers suggests declaring channel as
 * telefoon/balie/email/post and direction as inkomend/uitgaand. dossiq's
 * `contactmoment` schema has stored `phone`/`email`/`webformulier`/`chat`/
 * `social_media`/`balie` and `inbound`/`outbound` since the Dutch value
 * rename, and `ContactMomentService::validateInput()` enforces the first
 * list. A kind declaring the other spelling would refuse every entry this
 * app writes, and the refusal is caught and logged rather than shown, so the
 * timeline would simply stop filling. The declaration follows the data.
 *
 * A FIELD THE KIND DOES NOT DECLARE IS DROPPED, NOT REFUSED. That is why
 * `statuswijziging` gained `actor`, `explanation` and `label`, and why
 * `termijngebeurtenis` gained `occurredAt`, `startedAt` and `basis`: the two
 * writers that landed after the first seven kinds carry those values, and
 * without the declaration OpenRegister would have stored the entry, answered
 * 200 and quietly thrown the values away. A declaration only reaches an
 * instance when the repair step runs again, so a change to this list moves the
 * version in `appinfo/info.xml` with it.
 *
 * WHY ONLY INBOUND MAIL CARRIES A FOLLOW-UP. `carriesFollowUp` opens a
 * follow-up on EVERY entry of that kind, so a kind may declare one only when
 * every entry of it genuinely needs an answer. A message that reached a case
 * is open until a handler says otherwise. A logged phone call usually is not:
 * most are answered while the handler is still on the phone, and opening a
 * follow-up on each would make the follow-up count mean nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

/**
 * The declarations dossiq puts on an instance once.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
final class TimelineKinds {

	/**
	 * A call, a visit or a counter conversation a handler logged by hand.
	 *
	 * @var string
	 */
	public const CONTACTMOMENT = 'contactmoment';

	/**
	 * A message that arrived and was filed on this case.
	 *
	 * @var string
	 */
	public const MAIL_IN = 'mail-inkomend';

	/**
	 * A message this organisation sent from the case.
	 *
	 * @var string
	 */
	public const MAIL_OUT = 'mail-uitgaand';

	/**
	 * A message delivered to the citizen's government inbox.
	 *
	 * @var string
	 */
	public const PORTAL_MESSAGE = 'portaalbericht';

	/**
	 * The Awb 4:3a acknowledgement of receipt.
	 *
	 * @var string
	 */
	public const ACKNOWLEDGEMENT = 'ontvangstbevestiging';

	/**
	 * A move from one status to another.
	 *
	 * @var string
	 */
	public const STATUS_CHANGE = 'statuswijziging';

	/**
	 * A term starting, pausing, resuming or falling due.
	 *
	 * @var string
	 */
	public const TERM_EVENT = 'termijngebeurtenis';

	/**
	 * An act on a data subject request: a preview taken, an erasure run, an export asked for.
	 *
	 * One kind rather than three, because a handler reading the case wants the
	 * AVG story in one strand, and `act` already says which of the three it
	 * was. Three kinds would put the preview and the run it approved in
	 * different filters.
	 *
	 * @var string
	 */
	public const DATA_SUBJECT_REQUEST = 'avg-verzoek';

	/**
	 * Every declaration, in the shape `TimelineKindService::declareKind()` takes.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const DECLARATIONS = [
		[
			'slug' => self::CONTACTMOMENT,
			'title' => 'Contactmoment',
			'description' => 'A call, a visit or a counter conversation, logged by the handler who took it.',
			'properties' => [
				'channel' => [
					'type' => 'string',
					'enum' => ['phone', 'email', 'webformulier', 'chat', 'social_media', 'balie'],
				],
				'direction' => [
					'type' => 'string',
					'enum' => ['inbound', 'outbound'],
				],
				'nature' => ['type' => 'string'],
				'contactmomentId' => ['type' => 'string'],
			],
			'required' => ['channel', 'direction'],
			'followUp' => false,
		],
		[
			'slug' => self::MAIL_IN,
			'title' => 'Inkomende e-mail',
			'description' => 'A message that arrived and was filed on this case. Open until a handler closes it.',
			'properties' => [
				'sender' => ['type' => 'string'],
				'subject' => ['type' => 'string'],
				'outcome' => ['type' => 'string'],
				'intakeEntryId' => ['type' => 'string'],
			],
			'required' => ['sender'],
			'followUp' => true,
		],
		[
			'slug' => self::MAIL_OUT,
			'title' => 'Uitgaande e-mail',
			'description' => 'A message this organisation sent from the case.',
			'properties' => [
				'recipient' => ['type' => 'string'],
				'subject' => ['type' => 'string'],
				'documentId' => ['type' => 'string'],
			],
			'required' => ['recipient'],
			'followUp' => false,
		],
		[
			'slug' => self::PORTAL_MESSAGE,
			'title' => 'Bericht in de berichtenbox',
			'description' => 'A message delivered to the citizen inbox. The recipient identifier is deliberately not a field.',
			'properties' => [
				'subject' => ['type' => 'string'],
				'messageId' => ['type' => 'string'],
				'status' => ['type' => 'string'],
			],
			'required' => ['subject'],
			'followUp' => false,
		],
		[
			'slug' => self::ACKNOWLEDGEMENT,
			'title' => 'Ontvangstbevestiging',
			'description' => 'The Awb 4:3a acknowledgement of receipt, and how it was sent.',
			'properties' => [
				'channel' => ['type' => 'string'],
				'recipient' => ['type' => 'string'],
				'template' => ['type' => 'string'],
				'sentAt' => ['type' => 'string'],
			],
			'required' => ['channel'],
			'followUp' => false,
		],
		[
			'slug' => self::STATUS_CHANGE,
			'title' => 'Statuswijziging',
			'description' => 'A move from one status to another, who made it and why. The audit sidebar still holds the change history.',
			'properties' => [
				'from' => ['type' => 'string'],
				'to' => ['type' => 'string'],
				'actor' => ['type' => 'string'],
				'explanation' => ['type' => 'string'],
				'label' => ['type' => 'string'],
				'statusRecordId' => ['type' => 'string'],
			],
			'required' => ['to'],
			'followUp' => false,
		],
		[
			'slug' => self::TERM_EVENT,
			'title' => 'Termijngebeurtenis',
			'description' => 'A term starting, pausing, resuming or falling due, with the dates it moved.',
			'properties' => [
				'event' => ['type' => 'string'],
				'term' => ['type' => 'string'],
				'occurredAt' => ['type' => 'string'],
				'dueAt' => ['type' => 'string'],
				'startedAt' => ['type' => 'string'],
				'basis' => ['type' => 'string'],
				'termijnId' => ['type' => 'string'],
			],
			'required' => ['event'],
			'followUp' => false,
		],
		[
			'slug' => self::DATA_SUBJECT_REQUEST,
			'title' => 'AVG-verzoek',
			'description' => 'What OpenRegister was asked on behalf of a data subject, and what it answered.',
			'properties' => [
				'act' => [
					'type' => 'string',
					'enum' => ['preview', 'run', 'export'],
				],
				'previewId' => ['type' => 'string'],
				'exportId' => ['type' => 'string'],
				'erasable' => ['type' => 'integer'],
				'pseudonymised' => ['type' => 'integer'],
				'protected' => ['type' => 'integer'],
				'withheld' => ['type' => 'integer'],
				'complete' => ['type' => 'boolean'],
			],
			'required' => ['act'],
			'followUp' => false,
		],
	];

	/**
	 * The standard notes, in the shape `TextBlockService::declareBlock()` takes.
	 *
	 * A placeholder OpenRegister cannot fill is LEFT STANDING rather than
	 * emptied, which is what makes a half-filled block visible to the handler
	 * before they send it instead of after.
	 *
	 * @var array<int, array<string, string>>
	 */
	public const TEXT_BLOCKS = [
		[
			'slug' => 'dossiq-terugbelverzoek',
			'title' => 'Terugbelverzoek',
			'body' => 'Verzoek om terug te bellen over zaak {{identifier}}. Bereikbaar op: ',
		],
		[
			'slug' => 'dossiq-stukken-opgevraagd',
			'title' => 'Stukken opgevraagd',
			'body' => 'Voor zaak {{identifier}} zijn aanvullende stukken opgevraagd bij de aanvrager. Gevraagd is: ',
		],
		[
			'slug' => 'dossiq-telefonisch-toegelicht',
			'title' => 'Telefonisch toegelicht',
			'body' => 'De stand van zaak {{identifier}} is telefonisch toegelicht aan de aanvrager.',
		],
		[
			'slug' => 'dossiq-doorgezet-naar-collega',
			'title' => 'Doorgezet naar collega',
			'body' => 'Zaak {{identifier}} is inhoudelijk doorgezet. Reden: ',
		],
	];
}//end class
