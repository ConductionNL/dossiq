<?php

/**
 * Dossiq Bezwaar Audit Trail.
 *
 * The single writer of the bezwaar procedure's Awb and AVG entries. Every
 * entry is one row on OpenRegister's hash-chained audit trail of the record
 * it describes, a `hearingSession` or a `bacAdviceRequest`, written through
 * `AuditTrailMapper::createAuditTrailEntry()`. It used to be appended to an
 * `auditTrail` array on that same record, which anyone who could write the
 * record could edit, and which was not part of the hash chain (gate 23 rule 2).
 *
 * This class also owns the canonical Awb / AVG tag vocabulary (REQ-BH-8).
 * Every downstream consumer reads these exact values, so they are declared
 * once here and re-exported from HearingService for backwards compatibility.
 *
 * Entry shape, the row's context: an untagged entry is
 * `{event, actor, at, payload}`; a tagged entry is
 * `{event, tag, actor, at, payload}`. Key order is part of the contract
 * because consumers compare whole entries. The actor always comes from
 * IUserSession, never from the caller, and is `system` without a session.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes bezwaar entries onto OpenRegister's audit trail of their record.
 *
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */
class BezwaarAuditTrail {

	/**
	 * The action prefix of every bezwaar entry.
	 */
	public const ACTION_PREFIX = 'dossiq.bezwaar.';

	/**
	 * Awb art. 7:2 — hearing scheduled / invitation sent.
	 */
	public const TAG_SCHEDULED = 'awb-art-7:2';

	/**
	 * Awb art. 7:2 — invitation dispatched to an invitee.
	 */
	public const TAG_INVITATION_SENT = 'awb-art-7:2';

	/**
	 * Awb art. 7:3 — bezwaarmaker waived the hoorrecht.
	 */
	public const TAG_WAIVER = 'awb-art-7:3';

	/**
	 * Awb art. 7:4 — inspection of the file (inzage).
	 */
	public const TAG_INSPECTION = 'awb-art-7:4';

	/**
	 * Awb art. 7:6 — a confidential document was withheld.
	 */
	public const TAG_CONFIDENTIAL_WITHELD = 'awb-art-7:6';

	/**
	 * Awb art. 7:7 — verslaglegging (minutes / attendance record).
	 */
	public const TAG_VERSLAG = 'awb-art-7:7';

	/**
	 * Awb art. 7:13 — referral to the bezwaaradviescommissie.
	 */
	public const TAG_BAC_REFERRAL = 'awb-art-7:13';

	/**
	 * AVG art. 6 — consent basis for an audio recording.
	 */
	public const TAG_RECORDING_CONSENT = 'avg-art-6';

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession Acting identity source.
	 * @param IAppManager        $appManager  OpenRegister availability check.
	 * @param ContainerInterface $container   Resolves OpenRegister's ObjectService and AuditTrailMapper.
	 * @param LoggerInterface    $logger      Error log for an entry that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build one entry in the contract's key order.
	 *
	 * @param string               $event   Event slug.
	 * @param array<string, mixed> $payload Structured payload.
	 * @param string               $tag     Awb / AVG tag, or '' for an untagged entry.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function entry(string $event, array $payload, string $tag = ''): array {
		$entry = ['event' => $event];

		if ($tag !== '') {
			$entry['tag'] = $tag;
		}

		$entry['actor'] = $this->resolveActor();
		$entry['at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$entry['payload'] = $payload;

		return $entry;
	}//end entry()

	/**
	 * Write one entry as a row on the audit trail of the record it describes.
	 *
	 * @param string               $register   The register the record lives in.
	 * @param string               $schema     The record's schema.
	 * @param string               $objectUuid The record's uuid.
	 * @param string               $event      Event slug; the action is `dossiq.bezwaar.<event>`.
	 * @param array<string, mixed> $payload    Structured payload.
	 * @param string               $tag        Awb / AVG tag, or '' for an untagged entry.
	 *
	 * @return void
	 *
	 * @throws BezwaarEntryNotWrittenException When OpenRegister is absent, the record does not resolve, or the write fails.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function record(
		string $register,
		string $schema,
		string $objectUuid,
		string $event,
		array $payload,
		string $tag = '',
	): void {
		$this->write(
			register: $register,
			schema: $schema,
			objectUuid: $objectUuid,
			action: self::ACTION_PREFIX.$event,
			context: $this->entry(event: $event, payload: $payload, tag: $tag),
		);
	}//end record()

	/**
	 * Write a prepared context as a row on a record's audit trail.
	 *
	 * The repair step that copies the old arrays writes through here, with the
	 * original entry as context, so a copied row and a live one share one path.
	 *
	 * @param string               $register   The register the record lives in.
	 * @param string               $schema     The record's schema.
	 * @param string               $objectUuid The record's uuid.
	 * @param string               $action     The full action.
	 * @param array<string, mixed> $context    The row's context.
	 * @param string|null          $actorId    An explicit actor, or null for the session's.
	 *
	 * @return void
	 *
	 * @throws BezwaarEntryNotWrittenException When OpenRegister is absent, the record does not resolve, or the write fails.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function write(string $register, string $schema, string $objectUuid, string $action, array $context, ?string $actorId = null): void {
		if (in_array('openregister', (array)$this->appManager->getInstalledApps(), true) === false) {
			throw new BezwaarEntryNotWrittenException(
				action: $action,
				objectUuid: $objectUuid,
				entry: $context,
				reason: 'OpenRegister is not available, so the bezwaar entry '.$action.' cannot be written',
			);
		}

		try {
			$object = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->find($objectUuid, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$object = $e;
		}

		if (is_object($object) === false || $object instanceof Throwable) {
			throw new BezwaarEntryNotWrittenException(
				action: $action,
				objectUuid: $objectUuid,
				entry: $context,
				reason: 'The bezwaar record '.$objectUuid.' could not be resolved for '.$action,
				previous: ($object instanceof Throwable) ? $object : null,
			);
		}

		try {
			$this->container->get('OCA\\OpenRegister\\Db\\AuditTrailMapper')
				->createAuditTrailEntry(object: $object, action: $action, context: $context, actorId: $actorId, actorName: $actorId);
		} catch (Throwable $e) {
			throw new BezwaarEntryNotWrittenException(
				action: $action,
				objectUuid: $objectUuid,
				entry: $context,
				reason: 'The bezwaar entry '.$action.' could not be written',
				previous: $e,
			);
		}
	}//end write()

	/**
	 * Record the entry of a record this act just created, or undo the act.
	 *
	 * REQ-BAT-003: an act that creates a record saves it, writes the entry,
	 * and on a failed entry deletes the record it just created and raises.
	 * So no hearing or advice request stands without its entry.
	 *
	 * @param object               $objectService OpenRegister's ObjectService, the one the record was saved through.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param string               $objectUuid    The record just created.
	 * @param string               $event         Event slug.
	 * @param array<string, mixed> $payload       Structured payload.
	 * @param string               $tag           Awb / AVG tag, or ''.
	 *
	 * @return void
	 *
	 * @throws BezwaarEntryNotWrittenException When the entry could not be written; the record is deleted first.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function recordForNewRecord(
		object $objectService,
		string $register,
		string $schema,
		string $objectUuid,
		string $event,
		array $payload,
		string $tag = '',
	): void {
		try {
			$this->record(register: $register, schema: $schema, objectUuid: $objectUuid, event: $event, payload: $payload, tag: $tag);
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			$this->logger->error(
				'Dossiq bezwaar: entry not written, so the record it describes is deleted and the act refused',
				$notWritten->logContext()
			);
			try {
				$objectService->deleteObject(register: $register, schema: $schema, uuid: $objectUuid);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq bezwaar: the record without an entry could not be deleted',
					['object' => $objectUuid, 'exception' => $e->getMessage()]
				);
			}

			throw $notWritten;
		}//end try
	}//end recordForNewRecord()

	/**
	 * Record the entry first, then apply the change it records.
	 *
	 * REQ-BAT-003: an act that changes a record writes its entry first. A
	 * failed entry means the change is not made. A change that fails after the
	 * entry is written gets a `<event>-not-applied` entry, so the trail does
	 * not claim an act that did not happen, and the failure is raised.
	 *
	 * @param string               $register   The register.
	 * @param string               $schema     The schema.
	 * @param string               $objectUuid The record.
	 * @param string               $event      Event slug.
	 * @param array<string, mixed> $payload    Structured payload.
	 * @param string               $tag        Awb / AVG tag, or ''.
	 * @param callable             $apply      Makes the change; its return value is returned.
	 *
	 * @return mixed What $apply returned.
	 *
	 * @throws BezwaarEntryNotWrittenException When the entry could not be written; nothing is changed.
	 * @throws Throwable The change's own failure, after the not-applied entry.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function recordThenApply(
		string $register,
		string $schema,
		string $objectUuid,
		string $event,
		array $payload,
		string $tag,
		callable $apply,
	): mixed {
		$this->record(register: $register, schema: $schema, objectUuid: $objectUuid, event: $event, payload: $payload, tag: $tag);

		try {
			return $apply();
		} catch (Throwable $failure) {
			$this->recordRefusal(
				register: $register,
				schema: $schema,
				objectUuid: $objectUuid,
				event: $event.'-not-applied',
				payload: $payload + ['error' => $failure->getMessage()],
				tag: $tag,
			);

			throw $failure;
		}
	}//end recordThenApply()

	/**
	 * Record a refusal, whether or not the entry can be written.
	 *
	 * REQ-BAT-003: a refusal refuses either way. When its entry cannot be
	 * written, the full entry goes to the error log instead of being lost.
	 *
	 * @param string               $register   The register.
	 * @param string               $schema     The schema.
	 * @param string               $objectUuid The record.
	 * @param string               $event      Event slug.
	 * @param array<string, mixed> $payload    Structured payload.
	 * @param string               $tag        Awb / AVG tag, or ''.
	 *
	 * @return bool Whether the entry was written.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function recordRefusal(
		string $register,
		string $schema,
		string $objectUuid,
		string $event,
		array $payload,
		string $tag = '',
	): bool {
		try {
			$this->record(register: $register, schema: $schema, objectUuid: $objectUuid, event: $event, payload: $payload, tag: $tag);
		} catch (BezwaarEntryNotWrittenException $notWritten) {
			$this->logger->error('Dossiq bezwaar: entry not written', $notWritten->logContext());
			return false;
		}

		return true;
	}//end recordRefusal()

	/**
	 * The `migratedIndex` of every entry already copied onto a record's trail.
	 *
	 * Reads the record's `dossiq.bezwaar.*` rows through OpenRegister's
	 * `AuditTrailMapper::findAll()`, so the copy of the old embedded array can
	 * run twice and write nothing the second time. A read that fails throws:
	 * answering "nothing copied" would copy everything again.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return array<int, int> The copied indexes.
	 *
	 * @throws Throwable When the trail cannot be read.
	 *
	 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function copiedIndexes(string $objectUuid): array {
		$rows = $this->container->get('OCA\\OpenRegister\\Db\\AuditTrailMapper')->findAll(
			filters: ['object_uuid' => $objectUuid, 'action' => self::ACTION_PREFIX.'*']
		);

		$indexes = [];
		foreach ($rows as $row) {
			$context = (array)$row->getChanged();
			if (($context['migratedFrom'] ?? '') === 'auditTrail' && isset($context['migratedIndex']) === true) {
				$indexes[] = (int)$context['migratedIndex'];
			}
		}

		return $indexes;
	}//end copiedIndexes()

	/**
	 * Resolve the acting user UID from IUserSession.
	 *
	 * Identity is never taken from caller-supplied data; a session-less
	 * (cron / listener) context is recorded as `system`.
	 *
	 * @return string The acting UID, or 'system' when there is no session.
	 *
	 * @spec openspec/specs/bezwaar-hearing/spec.md
	 */
	public function resolveActor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveActor()
}//end class
