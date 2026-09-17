<?php

/**
 * Dossiq external consultation links.
 *
 * An advisory body outside the organisation is asked for advice on a case, and
 * answers without an account. Until now dossiq offered them a token page that
 * nobody could reach: `AdvisoryBodyService` records in its own source that
 * `issueSecureToken()` was deleted because it had no caller, so the read half
 * served a token nothing had ever minted.
 *
 * The body now receives an OpenRegister access link over the case
 * (openregister#3817) declaring reading and commenting. Their advice is a
 * comment written as the link, `link:<uuid>` on the audit trail, and the
 * handler collects it onto the consultation and names the verdict it carries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Invites an external advisory body over a case access link, and collects the
 * comment it writes back onto the consultation.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */
class ExternalConsultationLinkService {
	/**
	 * The actor type OpenRegister writes on a comment made through a link.
	 *
	 * Mirrors `OCA\OpenRegister\Service\Sharing\AccessLinkActs::LINK_ACTOR_TYPE`.
	 *
	 * @var string
	 */
	public const LINK_ACTOR_TYPE = 'openregister_links';

	/**
	 * How many of the case's comments to read when collecting advice.
	 *
	 * @var int
	 */
	private const NOTE_WINDOW = 200;

	/**
	 * Constructor.
	 *
	 * @param ConsultationService $consultations The consultation service.
	 * @param CaseSharingService $shares The case sharing service.
	 * @param CaseLinkShares $linkShares The share records the links are stored on.
	 * @param OpenRegisterSharingGateway $gateway Resolves the OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ConsultationService $consultations,
		private readonly CaseSharingService $shares,
		private readonly CaseLinkShares $linkShares,
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Invite the advisory body on a consultation, over a case access link.
	 *
	 * The link expires on the consultation's own deadline, so an advisory
	 * body that misses it loses the link rather than keeping the case open
	 * indefinitely.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $userId The handler inviting them.
	 * @param string|null $password An optional password, checked at use.
	 *
	 * @return array<string, mixed> The share and the address to send.
	 *
	 * @throws RuntimeException When the consultation cannot carry a link.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function invite(string $consultationId, string $userId, ?string $password = null): array {
		$consultation = $this->consultations->getConsultation(consultationId: $consultationId);
		if ($consultation === null) {
			throw new RuntimeException('That consultation is not there');
		}

		$caseId = trim((string)($consultation['parentCase'] ?? ''));
		if ($caseId === '') {
			throw new RuntimeException('A consultation without a case has nothing to publish');
		}

		$body = trim((string)($consultation['adviceAuthority'] ?? ''));
		if ($body === '') {
			throw new RuntimeException('Name the advisory body before you invite it');
		}

		$share = $this->shares->createTokenShare(
			caseId: $caseId,
			label: $body,
			createdBy: $userId,
			expiresAt: $this->deadlineOf(consultation: $consultation),
			capabilities: ['read', 'comment'],
			password: $password,
			sharedDocuments: [],
			extra: [
				'advisoryBody' => $body,
				'consultationId' => $consultationId,
			]
		);

		if (isset($share['error']) === true) {
			throw new RuntimeException((string)$share['error']);
		}

		$this->logger->info(
			'Dossiq: an external advisory body was invited over a case access link',
			['consultationId' => $consultationId, 'advisoryBody' => $body]
		);

		return $share;
	}//end invite()

	/**
	 * Collect the advisory body's comment onto the consultation.
	 *
	 * Only comments written as this consultation's own link count. A
	 * colleague's note on the same case is not advice, and a comment already
	 * collected is not collected twice.
	 *
	 * The verdict is the handler's to name: the body writes prose, and one of
	 * four codified outcomes is a judgement about that prose. The prose is
	 * stored verbatim beside it, so the classification can always be checked
	 * against what was actually written.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $advice The codified outcome the handler reads in the comment.
	 *
	 * @return array<string, mixed> What was recorded, or `collected => false` when there was nothing new.
	 *
	 * @throws RuntimeException When the consultation or its share is not there.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function collect(string $consultationId, string $advice): array {
		$consultation = $this->consultations->getConsultation(consultationId: $consultationId);
		if ($consultation === null) {
			throw new RuntimeException('That consultation is not there');
		}

		$caseId = trim((string)($consultation['parentCase'] ?? ''));
		$share = $this->shareFor(consultationId: $consultationId, caseId: $caseId);
		if ($share === null) {
			throw new RuntimeException('That consultation carries no link, so nobody outside can have answered it');
		}

		$actorId = 'link:' . trim((string)($share['accessLinkUuid'] ?? ''));
		$alreadyCollected = (int)($share['lastCollectedNote'] ?? 0);

		$newest = $this->newestComment(
			caseId: $caseId,
			actorId: $actorId,
			after: $alreadyCollected
		);

		if ($newest === null) {
			return ['collected' => false];
		}

		$recorded = $this->consultations->submitResponse(
			consultationId: $consultationId,
			response: [
				'advice' => $advice,
				'notes' => (string)($share['advisoryBody'] ?? '') . ': ' . (string)$newest['message'],
			]
		);

		$this->linkShares->markCollected(
			shareId: (string)($share['id'] ?? $share['uuid'] ?? ''),
			noteId: (int)$newest['id']
		);

		return [
			'collected' => true,
			'advisoryBody' => (string)($share['advisoryBody'] ?? ''),
			'noteId' => (int)$newest['id'],
			'consultation' => $recorded,
		];
	}//end collect()

	/**
	 * The share this consultation was published through.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $caseId The case the consultation hangs off.
	 *
	 * @return array<string, mixed>|null The share, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	private function shareFor(string $consultationId, string $caseId): ?array {
		if ($caseId === '') {
			return null;
		}

		foreach ($this->linkShares->listForCase(caseId: $caseId) as $share) {
			if ((string)($share['consultationId'] ?? '') === $consultationId) {
				return $share;
			}
		}

		return null;
	}//end shareFor()

	/**
	 * The newest comment on the case written as this link, later than the one
	 * already collected.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $actorId The link principal, `link:<uuid>`.
	 * @param int $after The id already collected.
	 *
	 * @return array<string, mixed>|null The comment, or null when there is nothing new.
	 *
	 * @throws RuntimeException When the comments cannot be read at all.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	private function newestComment(string $caseId, string $actorId, int $after): ?array {
		// A read that could not be made must not answer "nothing new". The
		// handler would read that as "the body has not replied", press on, and
		// close a consultation whose advice was sitting there unread.
		$notes = $this->gateway->noteService();
		if ($notes === null) {
			throw new RuntimeException('This instance cannot read the comments on a case, so advice cannot be collected');
		}

		try {
			$rows = $notes->getNotesForObject($caseId, self::NOTE_WINDOW);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'ExternalConsultationLinkService: could not read the case comments',
				['caseId' => $caseId, 'exception' => $failure->getMessage()]
			);
			throw new RuntimeException('The comments on this case could not be read, so advice cannot be collected');
		}

		$newest = null;
		foreach ((array)$rows as $row) {
			if ($this->isUncollectedLinkComment(row: $row, actorId: $actorId, after: $after) === false) {
				continue;
			}

			if ($newest === null || (int)$row['id'] > (int)$newest['id']) {
				$newest = $row;
			}
		}

		return $newest;
	}//end newestComment()

	/**
	 * Whether one comment is this link's, and newer than the one already
	 * collected.
	 *
	 * @param mixed $row The comment as OpenRegister returned it.
	 * @param string $actorId The link principal, `link:<uuid>`.
	 * @param int $after The id already collected.
	 *
	 * @return bool True when it counts as advice nobody has recorded yet.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	private function isUncollectedLinkComment(mixed $row, string $actorId, int $after): bool {
		if (is_array($row) === false) {
			return false;
		}

		if ((string)($row['actorType'] ?? '') !== self::LINK_ACTOR_TYPE) {
			return false;
		}

		if ((string)($row['actorId'] ?? '') !== $actorId) {
			return false;
		}

		return ((int)($row['id'] ?? 0) > $after);
	}//end isUncollectedLinkComment()

	/**
	 * The date the link should stop opening.
	 *
	 * The consultation's own latest response date, when it carries one. The
	 * link service supplies its own default when it does not.
	 *
	 * @param array<string, mixed> $consultation The consultation.
	 *
	 * @return string|null The deadline, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	private function deadlineOf(array $consultation): ?string {
		$deadline = trim((string)($consultation['latestResponseDate'] ?? ''));
		if ($deadline === '') {
			return null;
		}

		return $deadline;
	}//end deadlineOf()
}//end class
