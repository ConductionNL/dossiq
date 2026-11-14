<?php

/**
 * Dossiq Email Archival Service
 *
 * Lightweight archival surface that records linked emails as `caseDocument`
 * entries (ZGW informatieobject) and tracks PDF conversion status. The
 * heavy PDF conversion itself is delegated to Docudesk via an adapter; this
 * service owns the registry side of the workflow.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\EmailArchivalServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use Psr\Log\LoggerInterface;

/**
 * Archival surface for emails linked to a case.
 *
 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
 */
class EmailArchivalService implements EmailArchivalServiceInterface {

	use SearchesObjects;

	/**
	 * Maximum bytes processed synchronously; anything larger flips to async.
	 */
	public const SYNC_SIZE_THRESHOLD_BYTES = (5 * 1024 * 1024);

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shared OR/settings resolver.
	 * @param LoggerInterface $logger Logger.
	 * @param CorrespondentWriter $correspondents The party an inbound address belongs to.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CorrespondentWriter $correspondents,
	) {
	}//end __construct()

	/**
	 * Record the archival of a single linked email.
	 *
	 * @param string $caseId Owning case UUID.
	 * @param array<string, mixed> $metadata Email metadata (mailMessageId,
	 *                                       from, to, subject, sentAt,
	 *                                       sizeBytes).
	 *
	 * @return array{
	 *     archivalId: string,
	 *     mode: string,
	 *     pdfStatus: string,
	 * }
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
	 */
	public function archiveLinkedEmail(string $caseId, array $metadata): array {
		if ($caseId === '') {
			throw new InvalidArgumentException('caseId is required');
		}

		$size = (int)($metadata['sizeBytes'] ?? 0);
		$mode = $this->modeFor(size: $size);

		$archivalId = uniqid(prefix: 'archival-', more_entropy: true);

		$documentRecord = $this->documentRecord(caseId: $caseId, archivalId: $archivalId, metadata: $metadata, size: $size);

		$this->persistDocument(payload: $documentRecord);
		$this->appendCaseAudit(
			caseId: $caseId,
			eventType: 'email_linked',
			payload: [
				'mailMessageId' => $documentRecord['mailMessageId'],
				'subject' => $documentRecord['subject'],
				'mode' => $mode,
			]
		);

		return [
			'archivalId' => $archivalId,
			'mode' => $mode,
			'pdfStatus' => 'pending',
		];
	}//end archiveLinkedEmail()

	/**
	 * Whether a message of this size is archived inline or in the background.
	 *
	 * @param int $size The message size in bytes.
	 *
	 * @return string `async` above the sync threshold, `sync` otherwise.
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
	 */
	private function modeFor(int $size): string {
		$mode = 'sync';
		if ($size > self::SYNC_SIZE_THRESHOLD_BYTES) {
			$mode = 'async';
		}

		return $mode;
	}//end modeFor()

	/**
	 * The document record of a linked email.
	 *
	 * @param string               $caseId     Owning case UUID.
	 * @param string               $archivalId The archival identifier.
	 * @param array<string, mixed> $metadata   Email metadata.
	 * @param int                  $size       The message size in bytes.
	 *
	 * @return array<string, mixed> The document record.
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
	 */
	private function documentRecord(string $caseId, string $archivalId, array $metadata, int $size): array {
		// Who this message is FROM, as a party of the case rather than as
		// the address in the header. The address stays on `from`; this is
		// the party a handler can open. An address nobody on the case
		// holds leaves it empty: the intake does not create a party per
		// stranger who mails the case.
		$sender = $this->correspondents->partyHolding(
			caseId: $caseId,
			address: (string)($metadata['from'] ?? ''),
		);

		return [
			'archivalId' => $archivalId,
			'case' => $caseId,
			'source' => 'email',
			'sender' => $sender,
			'direction' => DocumentCorrespondents::DIRECTION_INCOMING,
			'mailMessageId' => (string)($metadata['mailMessageId'] ?? ''),
			'subject' => (string)($metadata['subject'] ?? ''),
			'from' => (string)($metadata['from'] ?? ''),
			'to' => (string)($metadata['to'] ?? ''),
			'sentAt' => (string)($metadata['sentAt'] ?? ''),
			'sizeBytes' => $size,
			'pdfStatus' => 'pending',
			'pdfAttempts' => 0,
		];
	}//end documentRecord()

	/*
	 * NO markComplete() HERE — YET.
	 *
	 * It set `pdfStatus: completed` + `pdfFileRef` through `updateArchival()`.
	 * Nothing called it: `EmailPdfRetryJob` only ever calls `markFailed()`,
	 * because the Docudesk PDF adapter it would branch on is not wired. A
	 * success writer for a success that cannot happen is dead code, so it is
	 * removed rather than left waiting — the retry job's own comment carries
	 * the instruction to add it back alongside the adapter, which is the
	 * moment it becomes reachable. `updateArchival()` is unchanged and
	 * `markFailed()` still uses it.
	 */

	/**
	 * Mark an archival attempt as failed and increment retry counter.
	 *
	 * @param string $archivalId Archival identifier.
	 * @param string $errorMessage Error context for the operator.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
	 */
	public function markFailed(string $archivalId, string $errorMessage): bool {
		$existing = $this->loadArchival(archivalId: $archivalId);
		$attempts = (int)($existing['pdfAttempts'] ?? 0);

		return $this->updateArchival(
			archivalId: $archivalId,
			fields: [
				'pdfStatus' => 'failed',
				'pdfLastError' => $errorMessage,
				'pdfAttempts' => ($attempts + 1),
				'pdfFailedAt' => date(DATE_ATOM),
			]
		);
	}//end markFailed()

	/**
	 * Find all archival records still in `failed` state.
	 *
	 * Used by `EmailPdfRetryJob` to retry archival; limit cap prevents the
	 * job from re-attempting an unbounded number of items in one pass.
	 *
	 * @param int $limit Hard upper bound on returned rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T09
	 */
	public function listFailedArchivals(int $limit = 50): array {
		$target = $this->archivalTarget();
		if ($target === null) {
			return [];
		}

		[$objectService, $register, $schema] = $target;
		$filters = ['source' => 'email', 'pdfStatus' => 'failed', '_limit' => $limit];
		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);

		return $this->retryable(rows: $rows);
	}//end listFailedArchivals()

	/**
	 * The failed rows that have retries left.
	 *
	 * @param array<int, array<string, mixed>> $rows The failed archival rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T09
	 */
	private function retryable(array $rows): array {
		// Cap further by retry-count so we never thrash on a permanently failed row.
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => ((int)($row['pdfAttempts'] ?? 0) < 3)
			)
		);
	}//end retryable()

	/**
	 * Persist the archival object.
	 *
	 * @param array<string, mixed> $payload Document payload.
	 *
	 * @return void
	 */
	private function persistDocument(array $payload): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			$this->logSchemaUnconfigured(payload: $payload);
			return;
		}

		$this->saveDocument(objectService: $objectService, register: $register, schema: $schema, payload: $payload);
	}//end persistDocument()

	/**
	 * Log that the document schema is not configured, so nothing is persisted.
	 *
	 * @param array<string, mixed> $payload Document payload.
	 *
	 * @return void
	 */
	private function logSchemaUnconfigured(array $payload): void {
		$this->logger->warning(
			'case_document_schema unconfigured — skipping archival persistence',
			['archivalId' => $payload['archivalId'] ?? '']
		);
	}//end logSchemaUnconfigured()

	/**
	 * Save the archival object, logging a failure.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register.
	 * @param string $schema The document schema.
	 * @param array<string, mixed> $payload Document payload.
	 *
	 * @return void
	 */
	private function saveDocument(object $objectService, string $register, string $schema, array $payload): void {
		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to persist email archival document',
				['archivalId' => $payload['archivalId'] ?? '', 'error' => $e->getMessage()]
			);
		}
	}//end saveDocument()

	/**
	 * Load an archival record by archivalId.
	 *
	 * @param string $archivalId Archival identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadArchival(string $archivalId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['archivalId' => $archivalId, '_limit' => 1],
		);

		return $rows[0] ?? null;
	}//end loadArchival()

	/**
	 * Update fields on an existing archival record.
	 *
	 * @param string $archivalId Archival identifier.
	 * @param array<string, mixed> $fields Fields to merge.
	 *
	 * @return bool
	 */
	private function updateArchival(string $archivalId, array $fields): bool {
		$existing = $this->loadArchival(archivalId: $archivalId);
		if ($existing === null) {
			return false;
		}

		$target = $this->archivalTarget();
		if ($target === null) {
			return false;
		}

		[$objectService, $register, $schema] = $target;

		$payload = array_merge($existing, $fields);
		try {
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to update archival record',
				['archivalId' => $archivalId, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end updateArchival()

	/**
	 * The object service, register and schema archival records live in.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The target, or null when OpenRegister or the schema is missing.
	 */
	private function archivalTarget(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_document_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end archivalTarget()

	/**
	 * Append an audit event to the case audit trail (OR-managed).
	 *
	 * @param string $caseId Case UUID.
	 * @param string $eventType Audit event name.
	 * @param array<string, mixed> $payload Event metadata.
	 *
	 * @return void
	 */
	private function appendCaseAudit(string $caseId, string $eventType, array $payload): void {
		// OR audit trails are append-only — best-effort write through the
		// ObjectService audit hook. Failures here are non-fatal.
		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return;
			}

			if (method_exists($objectService, 'logEvent') === true) {
				$objectService->logEvent($caseId, $eventType, $payload);
				return;
			}

			$this->logger->info(
				'Audit hook unavailable on ObjectService — falling back to logger',
				['caseId' => $caseId, 'eventType' => $eventType, 'payload' => $payload]
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Audit append failed (non-fatal)',
				['caseId' => $caseId, 'eventType' => $eventType, 'error' => $e->getMessage()]
			);
		}//end try
	}//end appendCaseAudit()
}//end class
