<?php

/**
 * Dossiq Bewijsstuk Service.
 *
 * Evidence-document (bewijsstuk) management (REQ-SUB-007): upload metadata
 * handling against a per-phase type whitelist, bewaartermijn assignment per
 * Selectielijst defaults, SHA-256 hash computation and verification, and
 * immutability once linked to a vaststelling. Persistence delegates to
 * OpenRegister via SettingsService; the hash/retention/whitelist logic is
 * pure and unit-tested.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\BewijsstukServiceInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evidence document upload, retention, hashing and immutability.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
 */
class BewijsstukService implements BewijsstukServiceInterface {

	use SearchesObjects;

	/**
	 * Allowed bewijsstuk types per source phase (REQ-SUB-007).
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TYPE_WHITELIST = [
		'request' => ['aanvraagdocument', 'budget', 'projectplan', 'cofinancieringsverklaring', 'ander'],
		'interimReport' => ['voortgangsrapport', 'timesheet', 'invoice', 'bankafschrift', 'deelnemerslijst', 'ander'],
		'determination' => ['eindrapport', 'auditorsStatement', 'invoice', 'bankafschrift', 'ander'],
		'verplichtingsbewijs' => ['deelnemerslijst', 'timesheet', 'invoice', 'ander'],
	];

	/**
	 * Default retention (years) per source phase, per Selectielijst 4.x.
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_BEWAARTERMIJN = [
		'request' => 7,
		'interimReport' => 7,
		'determination' => 10,
		'verplichtingsbewijs' => 7,
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a bewijsstuk type is allowed for a source phase (REQ-SUB-007).
	 *
	 * @param string $linkedIn The source phase.
	 * @param string $type The bewijsstuk type.
	 *
	 * @return bool True when the combination is on the whitelist.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function isTypeAllowed(string $linkedIn, string $type): bool {
		$allowed = self::TYPE_WHITELIST[$linkedIn] ?? null;
		if ($allowed === null) {
			return false;
		}

		return in_array($type, $allowed, true);
	}//end isTypeAllowed()

	/**
	 * Resolve the retention years for a source phase, preferring a
	 * regeling-configured override (REQ-SUB-007).
	 *
	 * @param string $linkedIn The source phase.
	 * @param int|null $override Regeling-configured retention, if any.
	 *
	 * @return int The retention years.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function bewaartermijnJaren(string $linkedIn, ?int $override = null): int {
		if ($override !== null && $override > 0) {
			return $override;
		}

		return (self::DEFAULT_BEWAARTERMIJN[$linkedIn] ?? 7);
	}//end bewaartermijnJaren()

	/**
	 * Compute the retention end date.
	 *
	 * @param DateTimeImmutable $from The reference date.
	 * @param int $jaren The retention years.
	 *
	 * @return DateTimeImmutable The retention end date.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function bewaartermijnEinde(DateTimeImmutable $from, int $jaren): DateTimeImmutable {
		return $from->add(new DateInterval('P' . max(1, $jaren) . 'Y'));
	}//end bewaartermijnEinde()

	/**
	 * Compute the SHA-256 hash of file contents (REQ-SUB-007).
	 *
	 * @param string $contents The raw file contents.
	 *
	 * @return string The lowercase hex digest.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function computeHash(string $contents): string {
		return hash('sha256', $contents);
	}//end computeHash()

	/**
	 * Verify file contents against a recorded hash (REQ-SUB-007).
	 *
	 * @param string $contents The raw file contents.
	 * @param string $expectedHash The recorded digest.
	 *
	 * @return bool True when the hash matches (constant-time compare).
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function verifyHash(string $contents, string $expectedHash): bool {
		return hash_equals($expectedHash, $this->computeHash(contents: $contents));
	}//end verifyHash()

	/**
	 * Create a bewijsstuk with type validation, retention assignment and a
	 * content hash (REQ-SUB-007).
	 *
	 * @param array<string, mixed> $payload The bewijsstuk metadata.
	 * @param string|null $contents Raw file contents to hash, if available.
	 * @param int|null $regelingRetention Regeling-configured retention override.
	 *
	 * @return array<string, mixed> The created bewijsstuk record.
	 *
	 * @throws OCSBadRequestException When validation/persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-007-bewijsstukken-management-with-bewaartermijn
	 */
	public function create(array $payload, ?string $contents = null, ?int $regelingRetention = null): array {
		return $this->createRecord(payload: $payload, evidence: ['contents' => $contents, 'regelingRetention' => $regelingRetention]);
	}//end create()

	/**
	 * Check the evidence type, build the retained record with its hash, and store it.
	 *
	 * @param array<string, mixed>                                      $payload  The bewijsstuk metadata.
	 * @param array{contents: string|null, regelingRetention: int|null} $evidence `contents` raw file contents to hash, if available;
	 *                                                                            `regelingRetention` regeling-configured retention override.
	 *
	 * @return array<string, mixed> The created bewijsstuk record.
	 *
	 * @throws OCSBadRequestException When validation/persistence fails.
	 */
	private function createRecord(array $payload, array $evidence): array {
		[$contents, $regelingRetention] = [$evidence['contents'], $evidence['regelingRetention']];
		$payload = array_diff_key($payload, CallerIdentity::KEYS);
		$linkedIn = (string)($payload['linkedIn'] ?? '');
		$type = (string)($payload['evidenceType'] ?? '');
		$this->requireAllowedType(linkedIn: $linkedIn, type: $type);

		$store = $this->resolve();

		$record = $this->recordOf(payload: $payload, retention: ['linkedIn' => $linkedIn, 'regelingRetention' => $regelingRetention]);
		if ($contents !== null) {
			$record['fileHashSha256'] = $this->computeHash(contents: $contents);
		}

		return $this->saveRecord(store: $store, record: $record);
	}//end createRecord()

	/**
	 * Refuse an evidence type the phase does not allow.
	 *
	 * @param string $linkedIn The phase the bewijsstuk is linked in.
	 * @param string $type     The evidence type.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the type is not allowed.
	 */
	private function requireAllowedType(string $linkedIn, string $type): void {
		if ($this->isTypeAllowed(linkedIn: $linkedIn, type: $type) === false) {
			throw new OCSBadRequestException('Bewijsstuktype "' . $type . '" is niet toegestaan voor fase "' . $linkedIn . '"');
		}
	}//end requireAllowedType()

	/**
	 * The record: the payload with its bewaartermijn and archive status.
	 *
	 * @param array<string, mixed>                                 $payload   The bewijsstuk metadata.
	 * @param array{linkedIn: string, regelingRetention: int|null} $retention `linkedIn` the phase the bewijsstuk is linked in; `regelingRetention`
	 *                                                                        regeling-configured retention override.
	 *
	 * @return array<string, mixed> The record to store.
	 */
	private function recordOf(array $payload, array $retention): array {
		[$linkedIn, $regelingRetention] = [$retention['linkedIn'], $retention['regelingRetention']];
		$now = new DateTimeImmutable();
		$jaren = $this->bewaartermijnJaren(linkedIn: $linkedIn, override: $regelingRetention);
		return array_merge(
			$payload,
			[
				'retentionPeriodYears' => $jaren,
				'retentionPeriodEnd' => $this->bewaartermijnEinde(from: $now, jaren: $jaren)->format('Y-m-d'),
				'archiveStatus' => 'active',
				'immutable' => ($linkedIn === 'determination'),
			]
		);
	}//end recordOf()

	/**
	 * Store the record, refusing with a domain error when the write fails.
	 *
	 * @param array{0: object, 1: string, 2: string} $store  OpenRegister's object service, the register and the bewijsstuk schema.
	 * @param array<string, mixed>                   $record The record to store.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @throws OCSBadRequestException When the write fails.
	 */
	private function saveRecord(array $store, array $record): array {
		[$objectService, $register, $schema] = $store;
		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: bewijsstuk create failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon bewijsstuk niet opslaan');
		}
	}//end saveRecord()

	/**
	 * Guard against mutating/deleting a bewijsstuk linked to a vaststelling
	 * (REQ-SUB-007 immutability).
	 *
	 * @param array<string, mixed> $bewijsstuk The bewijsstuk record.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the document is immutable.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-007-bewijsstukken-management-with-bewaartermijn
	 */
	public function assertMutable(array $bewijsstuk): void {
		if (($bewijsstuk['immutable'] ?? false) === true) {
			throw new OCSBadRequestException('Dit bewijsstuk is gekoppeld aan een vaststelling en is onveranderlijk');
		}
	}//end assertMutable()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$lookup = new SubsidieSchemaLookup(settingsService: $this->settingsService);
		return $lookup->resolve(schemaConfigKey: 'bewijsstuk_schema', unconfiguredMessage: 'Bewijsstuk-schema is niet geconfigureerd');
	}//end resolve()
}//end class
