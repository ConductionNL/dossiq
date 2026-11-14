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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseAccessLinkServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Mints, revokes, pauses and previews the access links a case share is made of.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */
class CaseAccessLinkService implements CaseAccessLinkServiceInterface {
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
	 * Constructor.
	 *
	 * @param OpenRegisterSharingGateway $gateway Resolves the OpenRegister services.
	 * @param AccessLinkProjection $projection Reduces a link body to what a holder may see.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly AccessLinkProjection $projection,
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function stateOf(array $link): string {
		if (empty($link['revokedAt']) === false) {
			return 'revoked';
		}

		if (($link['disabled'] ?? false) === true) {
			return 'paused';
		}

		if ($this->hasExpired(expiresAt: trim((string)($link['expiresAt'] ?? ''))) === true) {
			return 'expired';
		}

		return 'live';
	}//end stateOf()

	/**
	 * Whether an expiry moment has passed; a blank or unreadable one never has.
	 *
	 * @param string $expiresAt The trimmed expiry.
	 *
	 * @return bool
	 */
	private function hasExpired(string $expiresAt): bool {
		if ($expiresAt === '') {
			return false;
		}

		try {
			return new DateTimeImmutable($expiresAt) <= new DateTimeImmutable();
		} catch (\Exception $unreadable) {
			unset($unreadable);
			return false;
		}
	}//end hasExpired()

	/**
	 * What the holder of this link reads, as the handler should see it before
	 * sending the link.
	 *
	 * @param string $anchor The link anchor.
	 *
	 * @return array<string, mixed>|null The stripped body, or null when the link answers nothing.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function holderPreview(string $anchor): ?array {
		$tools = $this->previewTools();
		if ($tools === []) {
			return null;
		}

		try {
			$link = $tools['service']->resolve(trim($anchor));
			if ($link === null) {
				return null;
			}

			$body = $tools['reader']->read($link);
		} catch (\Throwable $failure) {
			$this->logger->error(
				'CaseAccessLinkService: could not read a link preview',
				['exception' => $failure->getMessage()]
			);
			return null;
		}

		return $this->strippedPreview(body: $body);
	}//end holderPreview()

	/**
	 * OpenRegister's link service and link reader, empty when either is not there.
	 *
	 * @return array{service: object, reader: object}|array{}
	 */
	private function previewTools(): array {
		$service = $this->gateway->accessLinkService();
		$reader = $this->gateway->accessLinkReader();
		if ($service === null || $reader === null) {
			return [];
		}

		return ['service' => $service, 'reader' => $reader];
	}//end previewTools()

	/**
	 * What a holder reads, stripped of the internals OpenRegister does not publish; null when the link read nothing.
	 *
	 * @param mixed $body What the link reader answered.
	 *
	 * @return array<string, mixed>|null
	 */
	private function strippedPreview(mixed $body): ?array {
		if (is_array($body) === false) {
			return null;
		}

		return $this->projection->strip(body: $body);
	}//end strippedPreview()

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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
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
		$prepared = $this->mintServiceAndCapabilities(capabilities: $capabilities);
		if (isset($prepared['error']) === true) {
			return $prepared;
		}

		[$service, $declared] = $prepared;

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
			return $this->mintFailed(subjectType: $subjectType, failure: $failure);
		}
	}//end mint()

	/**
	 * The access-link service and the capabilities to declare, or an error array when either is missing.
	 *
	 * @param array<int, string> $capabilities What the holder may do.
	 *
	 * @return array{0: object, 1: array<int, string>}|array{error: string} The service and capabilities, or an error.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function mintServiceAndCapabilities(array $capabilities): array {
		$service = $this->gateway->accessLinkService();
		if ($service === null) {
			return ['error' => 'This instance cannot publish links: OpenRegister does not offer them'];
		}

		$declared = $this->declaredCapabilities(capabilities: $capabilities);
		if ($declared === null) {
			return ['error' => 'A link may grant reading, commenting or uploading, and nothing else'];
		}

		return [$service, $declared];
	}//end mintServiceAndCapabilities()

	/**
	 * Log a mint OpenRegister refused and describe it.
	 *
	 * @param string $subjectType `object` or `file`.
	 * @param \Throwable $failure Why the mint failed.
	 *
	 * @return array<string, string> The error array.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function mintFailed(string $subjectType, \Throwable $failure): array {
		$this->logger->error(
			'CaseAccessLinkService: could not mint an access link',
			[
				'subjectType' => $subjectType,
				'exception' => $failure->getMessage(),
			]
		);
		return ['error' => 'Could not create the link'];
	}//end mintFailed()

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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function declaredCapabilities(array $capabilities): ?array {
		$names = $this->capabilityNames(capabilities: $capabilities);
		if (array_diff($names, self::CAPABILITIES) !== []) {
			return null;
		}

		return array_values(array_unique(array_merge(['read'], $names)));
	}//end declaredCapabilities()

	/**
	 * The asked-for capabilities trimmed and lowercased, blanks left out, in the order asked.
	 *
	 * @param array<int, string> $capabilities What the caller asked for.
	 *
	 * @return array<int, string>
	 */
	private function capabilityNames(array $capabilities): array {
		$names = [];
		foreach ($capabilities as $candidate) {
			$name = strtolower(trim((string)$candidate));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end capabilityNames()

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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
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
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function trimmedOrNull(?string $value): ?string {
		$trimmed = trim((string)$value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end trimmedOrNull()
}//end class
