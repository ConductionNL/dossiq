<?php

/**
 * Dossiq portal Woo answer
 *
 * The requester answers the organisation's question from Mijn zaken
 * (woo-dossier-shared-with-the-requester REQ-WDS-001). The answer lands on the
 * open aanvullingsverzoek it names, the handler is told, and the request stays
 * open: only the handler's word closes it and resumes the term.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a requester's answer on their own open request. Fails closed.
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */
class PortalWooAnswer {

	use SearchesObjects;

	/**
	 * The longest answer accepted, in characters.
	 */
	public const MAX_LENGTH = 4000;

	/**
	 * The act ApplicantPortalActs records.
	 */
	public const ACT = 'answer';

	/**
	 * Who `recordAnswer()` names as having recorded it.
	 */
	public const RECORDED_BY = 'portal';

	/**
	 * Constructor.
	 *
	 * @param SettingsService                     $settings   Bridge to OpenRegister.
	 * @param AanvullingsverzoekService           $requests   The requests themselves.
	 * @param AanvullingsverzoekResolutionService $resolution Records the answer on the open request.
	 * @param ApplicantPortalActs                 $acts       Tells the handler.
	 * @param LoggerInterface                     $logger     Logger.
	 * @param DocumentRecordStore|null            $documents  Stores the requester's files on the case.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly AanvullingsverzoekService $requests,
		private readonly AanvullingsverzoekResolutionService $resolution,
		private readonly ApplicantPortalActs $acts,
		private readonly LoggerInterface $logger,
		private readonly ?DocumentRecordStore $documents = null,
	) {
	}//end __construct()

	/**
	 * Record the answer, or refuse with nothing written.
	 *
	 * Every request that is not the caller's, not open, or not the open
	 * request of a case of the same subject answers 404: the caller learns
	 * nothing about a request that is not theirs.
	 *
	 * @param WooAnswerSubmission $submission The subject portaliq vouches for, the request, the answer and its files.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	public function submit(WooAnswerSubmission $submission): array {
		$text = trim($submission->answer);
		if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
			return ['status' => 400, 'body' => ['error' => 'invalid', 'message' => 'Write an answer of at most 4000 characters.']];
		}

		$run = $this->openRun(submission: $submission);
		if ($run === null) {
			return ['status' => 503, 'body' => ['error' => 'unavailable']];
		}

		return (array)$this->runAsSystemIfAvailable(
			objectService: $run->objectService,
			operation: fn (): array => $this->record(run: $run)
		);
	}//end submit()

	/**
	 * The run of one answer, or null without OpenRegister, a register or a case schema.
	 *
	 * @param WooAnswerSubmission $submission What was sent.
	 *
	 * @return WooAnswerRun|null
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function openRun(WooAnswerSubmission $submission): ?WooAnswerRun {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$caseSchema = (string)$this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return null;
		}

		$run = new WooAnswerRun();
		$run->objectService = $objectService;
		$run->register = $register;
		$run->caseSchema = $caseSchema;
		$run->moment = ($submission->now ?? new DateTimeImmutable());
		$run->subjectRef = trim($submission->subjectRef);
		$run->requestId = trim($submission->requestId);
		$run->text = trim($submission->answer);
		$run->attachments = $submission->attachments;

		return $run;
	}//end openRun()

	/**
	 * Check the request, store the answer, file the attachments and tell the handler.
	 *
	 * @param WooAnswerRun $run The answer being recorded.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function record(WooAnswerRun $run): array {
		$caseId = $this->answerableCase(run: $run);
		if ($caseId === null) {
			return ['status' => 404, 'body' => ['error' => 'not_found']];
		}

		$refused = $this->storeAnswer(run: $run, caseId: $caseId);
		if ($refused !== null) {
			return $refused;
		}

		$filed = $this->fileAttachments(caseId: $caseId, attachments: $run->attachments);

		$this->acts->recordWrite(
			caseId: $caseId,
			act: self::ACT,
			fields: $this->answeredFields(filed: $filed),
			occurredAt: $run->moment->format('c')
		);

		return ['status' => 200, 'body' => ['requestId' => $run->requestId, 'state' => 'open', 'attachments' => $filed]];
	}//end record()

	/**
	 * Record the answer on the case's resolution and on the request.
	 *
	 * @param WooAnswerRun $run    The answer being recorded.
	 * @param string       $caseId The case the request is on.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The 503 when the write is refused, else null.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function storeAnswer(WooAnswerRun $run, string $caseId): ?array {
		try {
			$this->resolution->recordAnswer(
				caseId: $caseId,
				received: [],
				complete: false,
				userId: self::RECORDED_BY,
				when: $run->moment
			);
			$this->requests->write(
				request: ['applicantAnswer' => $run->text, 'applicantAnsweredAt' => $run->moment->format('c')],
				id: $run->requestId
			);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'Dossiq: a portal answer could not be recorded',
				['app' => AppIdentity::APP_ID, 'case' => $caseId, 'rule' => $e->getMessage()]
			);
			return ['status' => 503, 'body' => ['error' => 'unavailable']];
		}

		return null;
	}//end storeAnswer()

	/**
	 * The fields the handler is told the answer wrote.
	 *
	 * @param int $filed How many attachments were filed on the case.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function answeredFields(int $filed): array {
		$fields = ['applicantAnswer'];
		if ($filed > 0) {
			$fields[] = 'documents';
		}

		return $fields;
	}//end answeredFields()

	/**
	 * Store the requester's files in the case's folder.
	 *
	 * The document projection turns each file in a case folder into an
	 * incoming document record, so the handler finds the attachments with the
	 * case's other documents. A file that cannot be stored is logged and not
	 * counted; the answer itself stands.
	 *
	 * @param string                                          $caseId      The case.
	 * @param list<array{name: string, content: string}> $attachments The files.
	 *
	 * @return int How many were stored.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function fileAttachments(string $caseId, array $attachments): int {
		if ($this->documents === null || $attachments === []) {
			return 0;
		}

		$filed = 0;
		foreach ($attachments as $attachment) {
			try {
				$this->documents->storeFileOnObject(objectId: $caseId, fileName: $attachment['name'], content: $attachment['content']);
				$filed++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: a file sent with a portal answer could not be stored on the case',
					['app' => AppIdentity::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
				);
			}
		}

		return $filed;
	}//end fileAttachments()

	/**
	 * The case of the request, when the caller may answer it; else null.
	 *
	 * The request must be the caller's and open, its case the caller's, and
	 * it must be that case's open request. A failed check is logged and
	 * answers null.
	 *
	 * @param WooAnswerRun $run The answer being recorded.
	 *
	 * @return string|null The case id, or null.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function answerableCase(WooAnswerRun $run): ?string {
		try {
			$caseId = $this->callersCaseOfRequest(run: $run);
			if ($caseId === null) {
				return null;
			}

			$open = $this->requests->openFor(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a portal answer could not check its request',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

		if ($this->isRequest(open: $open, requestId: $run->requestId) === false) {
			return null;
		}

		return $caseId;
	}//end answerableCase()

	/**
	 * The case of an open request of the caller, when that case is the caller's too.
	 *
	 * @param WooAnswerRun $run The answer being recorded.
	 *
	 * @return string|null The case id, or null.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function callersCaseOfRequest(WooAnswerRun $run): ?string {
		if ($run->subjectRef === '' || $run->requestId === '') {
			return null;
		}

		$request = $this->findObjectAsArray(
			objectService: $run->objectService,
			register: $run->register,
			schema: AanvullingsverzoekService::SCHEMA,
			id: $run->requestId
		);
		if ($this->isOpenFor(row: $request, subjectRef: $run->subjectRef, open: true) === false) {
			return null;
		}

		$caseId = (string)($request['case'] ?? '');
		$case = $this->findObjectAsArray(objectService: $run->objectService, register: $run->register, schema: $run->caseSchema, id: $caseId);
		if ($this->isOpenFor(row: $case, subjectRef: $run->subjectRef, open: false) === false) {
			return null;
		}

		return $caseId;
	}//end callersCaseOfRequest()

	/**
	 * Whether the open request of the case is the request answered.
	 *
	 * @param array<string, mixed>|null $open      The case's open request, or null.
	 * @param string                    $requestId The request answered.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	private function isRequest(?array $open, string $requestId): bool {
		return $open !== null && $this->idOf(row: $open) === $requestId;
	}//end isRequest()

	/**
	 * Whether a row belongs to the subject and, when asked, is an open request.
	 *
	 * @param array<string, mixed>|null $row        The request or the case.
	 * @param string                    $subjectRef The portal subject.
	 * @param bool                      $open       Whether the row must be in the state `open`.
	 *
	 * @return bool
	 */
	private function isOpenFor(?array $row, string $subjectRef, bool $open): bool {
		if ($row === null || (string)($row['portalSubject'] ?? '') !== $subjectRef) {
			return false;
		}

		return $open === false || (string)($row['state'] ?? '') === 'open';
	}//end isOpenFor()

	/**
	 * A row's id, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
	}//end idOf()
}//end class
