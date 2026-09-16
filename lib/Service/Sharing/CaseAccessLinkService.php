<?php

/**
 * Dossiq case access links.
 *
 * A case is shared with somebody who has no account by minting an OpenRegister
 * access link over it (openregister#3817). OpenRegister owns the anchor, the
 * expiry, the password check, the revoke, and the single 404 that covers
 * unknown, revoked, paused and expired alike. Dossiq owns one question only:
 * which subject is published, and what its holder may do with it.
 *
 * This class replaces CaseTokenShareService. That class minted through the
 * shares leaf, which grants reading and nothing else, so an advisory body
 * could read a case and had no way to answer it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
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
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Mints, revokes, pauses and previews the access links a case share is made of.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */
class CaseAccessLinkService {
	/**
	 * The capabilities a case link may declare.
	 *
	 * Mirrors `OCA\OpenRegister\Db\AccessLink::CAPABILITIES`. It is repeated
	 * here so dossiq refuses a capability its own UI should never send,
	 * before the request crosses into OpenRegister.
	 *
	 * @var array<int, string>
	 */
	public const CAPABILITIES = ['read', 'comment', 'upload'];

	/**
	 * What a case share declares when the handler names nothing.
	 *
	 * Reading and commenting, because the case a share exists for is nearly
	 * always one somebody outside is expected to answer. Uploading is opted
	 * into, never assumed.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_CAPABILITIES = ['read', 'comment'];

	/**
	 * How long a share lasts when the handler names no end date.
	 *
	 * OpenRegister refuses to mint a link with no expiry, so dossiq must
	 * either supply one or refuse the share. Thirty days is the shorter of
	 * the two answers, and the handler can always mint another.
	 *
	 * @var string
	 */
	public const DEFAULT_TTL = '+30 days';

	/**
	 * The `@self` keys a holder may be shown.
	 *
	 * Mirrors `OCA\OpenRegister\Service\Sharing\AccessLinkReader`'s own
	 * allow-list. Dossiq applies it a second time on everything it renders
	 * from a link body, so an internal that OpenRegister starts publishing
	 * tomorrow does not reach a holder through dossiq today.
	 *
	 * @var array<int, string>
	 */
	public const PUBLISHABLE_SELF_KEYS = [
		'id',
		'uuid',
		'name',
		'description',
		'summary',
		'register',
		'schema',
		'published',
		'depublished',
		'created',
		'updated',
	];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterSharingGateway $gateway Resolves the OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mint a link over a case.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $userId The handler minting it.
	 * @param array<int, string> $capabilities What the holder may do.
	 * @param string|null $expiresAt When the link stops answering.
	 * @param string|null $password An optional password, checked at use.
	 * @param string|null $label What the link is called.
	 *
	 * @return array<string, mixed> The link row plus its url, or an error array.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function mintCaseLink(
		string $caseId,
		string $userId,
		array $capabilities = self::DEFAULT_CAPABILITIES,
		?string $expiresAt = null,
		?string $password = null,
		?string $label = null,
	): array {
		return $this->mint(
			userId: $userId,
			subjectType: 'object',
			subjectId: $caseId,
			capabilities: $capabilities,
			expiresAt: $expiresAt,
			password: $password,
			label: $label
		);
	}//end mintCaseLink()

	/**
	 * Mint a link over one file of a case.
	 *
	 * The subject is addressed as `<objectUuid>/<fileId>`, which is what
	 * OpenRegister reads a file subject as. A holder of this link receives
	 * that file and nothing else of the case.
	 *
	 * @param string $caseId The uuid of the object carrying the file.
	 * @param string $fileId The file id on that object.
	 * @param string $userId The handler minting it.
	 * @param string|null $expiresAt When the link stops answering.
	 * @param string|null $password An optional password, checked at use.
	 * @param string|null $label What the link is called.
	 *
	 * @return array<string, mixed> The link row plus its url, or an error array.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
	 */
	public function mintFileLink(
		string $caseId,
		string $fileId,
		string $userId,
		?string $expiresAt = null,
		?string $password = null,
		?string $label = null,
	): array {
		$caseId = trim($caseId);
		$fileId = trim($fileId);
		if ($caseId === '' || $fileId === '') {
			return ['error' => 'A file link must name both the case and the file'];
		}

		return $this->mint(
			userId: $userId,
			subjectType: 'file',
			subjectId: $caseId . '/' . $fileId,
			capabilities: ['read'],
			expiresAt: $expiresAt,
			password: $password,
			label: $label
		);
	}//end mintFileLink()

	/**
	 * Revoke a link.
	 *
	 * OpenRegister revokes a link only for the principal that minted it, so a
	 * colleague's revoke comes back false. That false is reported, never
	 * swallowed: a revoke that did not happen must not read as one that did.
	 *
	 * @param int $linkId The link row id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when OpenRegister revoked it.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revokeLink(int $linkId, string $userId): bool {
		$service = $this->gateway->accessLinkService();
		if ($service === null) {
			return false;
		}

		try {
			$revoked = (bool)$service->revoke($linkId, $userId);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'CaseAccessLinkService: could not revoke an access link',
				['linkId' => $linkId, 'exception' => $failure->getMessage()]
			);
			return false;
		}

		if ($revoked === false) {
			$this->logger->warning(
				'CaseAccessLinkService: OpenRegister refused the revoke, which it does unless the caller minted the link',
				['linkId' => $linkId, 'userId' => $userId]
			);
		}

		return $revoked;
	}//end revokeLink()

	/**
	 * Switch a link off, or back on, without revoking it.
	 *
	 * @param int $linkId The link row id.
	 * @param string $userId The principal asking.
	 * @param bool $paused True to switch it off.
	 *
	 * @return array<string, mixed>|null The updated link, or null when there was none to update.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function setPaused(int $linkId, string $userId, bool $paused): ?array {
		$service = $this->gateway->accessLinkService();
		if ($service === null) {
			return null;
		}

		try {
			$updated = $service->setDisabled($linkId, $userId, $paused);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'CaseAccessLinkService: could not pause an access link',
				['linkId' => $linkId, 'exception' => $failure->getMessage()]
			);
			return null;
		}

		if (is_array($updated) === false) {
			return null;
		}

		return $updated;
	}//end setPaused()

	/**
	 * Which of the four states a link row is in.
	 *
	 * The order matters. A revoked link that has also expired is revoked,
	 * because that is the fact the handler acted on.
	 *
	 * @param array<string, mixed> $link A serialised link row.
	 *
	 * @return string One of `revoked`, `paused`, `expired`, `live`.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function stateOf(array $link): string {
		if (empty($link['revokedAt']) === false) {
			return 'revoked';
		}

		if (($link['disabled'] ?? false) === true) {
			return 'paused';
		}

		$expiresAt = trim((string)($link['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			try {
				if (new DateTimeImmutable($expiresAt) <= new DateTimeImmutable()) {
					return 'expired';
				}
			} catch (\Exception $unreadable) {
				unset($unreadable);
			}
		}

		return 'live';
	}//end stateOf()

	/**
	 * What the holder of this link reads, as the handler should see it before
	 * sending the link.
	 *
	 * @param string $anchor The link anchor.
	 *
	 * @return array<string, mixed>|null The stripped body, or null when the link answers nothing.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function holderPreview(string $anchor): ?array {
		$service = $this->gateway->accessLinkService();
		$reader = $this->gateway->accessLinkReader();
		if ($service === null || $reader === null) {
			return null;
		}

		try {
			$link = $service->resolve(trim($anchor));
			if ($link === null) {
				return null;
			}

			$body = $reader->read($link);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'CaseAccessLinkService: could not read a link preview',
				['exception' => $failure->getMessage()]
			);
			return null;
		}

		if (is_array($body) === false) {
			return null;
		}

		return $this->stripInternals(body: $body);
	}//end holderPreview()

	/**
	 * Reduce a link body to what a holder may be shown.
	 *
	 * Every `@self` is cut down to the keys OpenRegister publishes, and every
	 * other top-level key starting with `@` is dropped. This runs on dossiq's
	 * side of a boundary OpenRegister already guards, on purpose: the two
	 * allow-lists have to drift apart before an internal reaches a holder.
	 *
	 * @param array<string, mixed> $body The body OpenRegister returned.
	 *
	 * @return array<string, mixed> The body, stripped.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function stripInternals(array $body): array {
		if (isset($body['subject']) === true && is_array($body['subject']) === true) {
			$body['subject'] = $this->strippedRow(row: $body['subject']);
		}

		if (isset($body['results']) === true && is_array($body['results']) === true) {
			$rows = [];
			foreach ($body['results'] as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$rows[] = $this->strippedRow(row: $row);
			}

			$body['results'] = $rows;
		}

		return $body;
	}//end stripInternals()

	/**
	 * One row of a link body, reduced.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row, stripped.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	private function strippedRow(array $row): array {
		$stripped = [];
		foreach ($row as $key => $value) {
			if (str_starts_with((string)$key, '@') === true && (string)$key !== '@self') {
				continue;
			}

			$stripped[$key] = $value;
		}

		if (isset($stripped['@self']) === true) {
			$self = [];
			if (is_array($stripped['@self']) === true) {
				foreach (self::PUBLISHABLE_SELF_KEYS as $allowed) {
					if (array_key_exists($allowed, $stripped['@self']) === true) {
						$self[$allowed] = $stripped['@self'][$allowed];
					}
				}
			}

			$stripped['@self'] = $self;
		}

		return $stripped;
	}//end strippedRow()

	/**
	 * Mint one link, whatever its subject.
	 *
	 * @param string $userId The principal minting it.
	 * @param string $subjectType `object` or `file`.
	 * @param string $subjectId The subject.
	 * @param array<int, string> $capabilities What the holder may do.
	 * @param string|null $expiresAt When the link stops answering.
	 * @param string|null $password An optional password.
	 * @param string|null $label What the link is called.
	 *
	 * @return array<string, mixed> The link row plus its url, or an error array.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function mint(
		string $userId,
		string $subjectType,
		string $subjectId,
		array $capabilities,
		?string $expiresAt,
		?string $password,
		?string $label,
	): array {
		$service = $this->gateway->accessLinkService();
		if ($service === null) {
			return ['error' => 'This instance cannot publish links: OpenRegister does not offer them'];
		}

		$declared = $this->declaredCapabilities(capabilities: $capabilities);
		if ($declared === null) {
			return ['error' => 'A link may grant reading, commenting or uploading, and nothing else'];
		}

		try {
			return (array)$service->mint(
				$userId,
				$subjectType,
				$subjectId,
				$declared,
				$this->expiry(expiresAt: $expiresAt),
				$this->trimmedOrNull(value: $password),
				$this->trimmedOrNull(value: $label)
			);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'CaseAccessLinkService: could not mint an access link',
				[
					'subjectType' => $subjectType,
					'exception' => $failure->getMessage(),
				]
			);
			return ['error' => 'Could not create the link'];
		}
	}//end mint()

	/**
	 * The capabilities to declare, or null when one of them is not a
	 * capability at all.
	 *
	 * Reading is always declared, because a link that reads nothing is a link
	 * that does nothing.
	 *
	 * @param array<int, string> $capabilities What the caller asked for.
	 *
	 * @return array<int, string>|null The capabilities, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function declaredCapabilities(array $capabilities): ?array {
		$declared = ['read'];
		foreach ($capabilities as $candidate) {
			$name = strtolower(trim((string)$candidate));
			if ($name === '') {
				continue;
			}

			if (in_array($name, self::CAPABILITIES, true) === false) {
				return null;
			}

			if (in_array($name, $declared, true) === false) {
				$declared[] = $name;
			}
		}

		return $declared;
	}//end declaredCapabilities()

	/**
	 * The expiry to mint with.
	 *
	 * OpenRegister refuses a link with no expiry, so dossiq supplies one
	 * rather than refusing the share. The default is deliberately short.
	 *
	 * @param string|null $expiresAt What the handler asked for.
	 *
	 * @return string An ISO 8601 date.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function expiry(?string $expiresAt): string {
		$raw = trim((string)$expiresAt);
		if ($raw !== '') {
			return $raw;
		}

		return (new DateTimeImmutable(self::DEFAULT_TTL))->format('c');
	}//end expiry()

	/**
	 * A trimmed string, or null when there is nothing in it.
	 *
	 * @param string|null $value The value.
	 *
	 * @return string|null The value, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function trimmedOrNull(?string $value): ?string {
		$trimmed = trim((string)$value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end trimmedOrNull()
}//end class
