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
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
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
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly AanvullingsverzoekService $requests,
		private readonly AanvullingsverzoekResolutionService $resolution,
		private readonly ApplicantPortalActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the answer, or refuse with nothing written.
	 *
	 * Every request that is not the caller's, not open, or not the open
	 * request of a case of the same subject answers 404: the caller learns
	 * nothing about a request that is not theirs.
	 *
	 * @param string                 $subjectRef The portal subject portaliq vouches for.
	 * @param string                 $requestId  The aanvullingsverzoek the row names.
	 * @param string                 $answer     What the requester wrote.
	 * @param DateTimeImmutable|null $now        The moment of the answer.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	public function answer(string $subjectRef, string $requestId, string $answer, ?DateTimeImmutable $now = null): array {
		$text = trim($answer);
		if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
			return ['status' => 400, 'body' => ['error' => 'invalid', 'message' => 'Write an answer of at most 4000 characters.']];
		}

		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$caseSchema = (string)$this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return ['status' => 503, 'body' => ['error' => 'unavailable']];
		}

		$moment = ($now ?? new DateTimeImmutable());

		return (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->record(
				objectService: $objectService,
				register: $register,
				caseSchema: $caseSchema,
				subjectRef: trim($subjectRef),
				requestId: trim($requestId),
				text: $text,
				moment: $moment
			)
		);
	}//end answer()

	/**
	 * The checks and the writes, as the system.
	 *
	 * @param object            $objectService OpenRegister's object service.
	 * @param string            $register      The register.
	 * @param string            $caseSchema    The case schema.
	 * @param string            $subjectRef    The portal subject.
	 * @param string            $requestId     The request id.
	 * @param string            $text          The answer.
	 * @param DateTimeImmutable $moment        When.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 */
	private function record(
		object $objectService,
		string $register,
		string $caseSchema,
		string $subjectRef,
		string $requestId,
		string $text,
		DateTimeImmutable $moment,
	): array {
		$notFound = ['status' => 404, 'body' => ['error' => 'not_found']];
		$caseId = $this->answerableCase(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			subjectRef: $subjectRef,
			requestId: $requestId
		);
		if ($caseId === null) {
			return $notFound;
		}

		try {
			$this->resolution->recordAnswer(
				caseId: $caseId,
				received: [],
				complete: false,
				userId: self::RECORDED_BY,
				when: $moment
			);
			$this->requests->write(
				request: ['applicantAnswer' => $text, 'applicantAnsweredAt' => $moment->format('c')],
				id: $requestId
			);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'Dossiq: a portal answer could not be recorded',
				['app' => Application::APP_ID, 'case' => $caseId, 'rule' => $e->getMessage()]
			);
			return ['status' => 503, 'body' => ['error' => 'unavailable']];
		}

		$this->acts->recordWrite(caseId: $caseId, act: self::ACT, fields: ['applicantAnswer'], occurredAt: $moment->format('c'));

		return ['status' => 200, 'body' => ['requestId' => $requestId, 'state' => 'open']];
	}//end record()

	/**
	 * The case of a request the subject may answer, or null.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $caseSchema    The case schema.
	 * @param string $subjectRef    The portal subject.
	 * @param string $requestId     The request id.
	 *
	 * @return string|null The case id, or null when the request is not answerable.
	 */
	private function answerableCase(
		object $objectService,
		string $register,
		string $caseSchema,
		string $subjectRef,
		string $requestId,
	): ?string {
		if ($subjectRef === '' || $requestId === '') {
			return null;
		}

		try {
			$request = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: AanvullingsverzoekService::SCHEMA,
				id: $requestId
			);
			if ($this->isOpenFor(row: $request, subjectRef: $subjectRef, open: true) === false) {
				return null;
			}

			$caseId = (string)($request['case'] ?? '');
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
			if ($this->isOpenFor(row: $case, subjectRef: $subjectRef, open: false) === false) {
				return null;
			}

			$open = $this->requests->openFor(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a portal answer could not check its request',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

		if ($open === null || $this->idOf(row: $open) !== $requestId) {
			return null;
		}

		return $caseId;
	}//end answerableCase()

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
