<?php

/**
 * Procest Bewijsstuk Service.
 *
 * Evidence-document (bewijsstuk) management (REQ-SUB-007): upload metadata
 * handling against a per-phase type whitelist, bewaartermijn assignment per
 * Selectielijst defaults, SHA-256 hash computation and verification, and
 * immutability once linked to a vaststelling. Persistence delegates to
 * OpenRegister via SettingsService; the hash/retention/whitelist logic is
 * pure and unit-tested.
 *
 * @category Service
 * @package  OCA\Procest\Service\Subsidie
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evidence document upload, retention, hashing and immutability.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class BewijsstukService
{
    /**
     * Allowed bewijsstuk types per source phase (REQ-SUB-007).
     *
     * @var array<string, array<int, string>>
     */
    public const TYPE_WHITELIST = [
        'aanvraag'            => ['aanvraagdocument', 'begroting', 'projectplan', 'cofinancieringsverklaring', 'ander'],
        'tussenrapportage'    => ['voortgangsrapport', 'urenstaat', 'factuur', 'bankafschrift', 'deelnemerslijst', 'ander'],
        'vaststelling'        => ['eindrapport', 'accountantsverklaring', 'factuur', 'bankafschrift', 'ander'],
        'verplichtingsbewijs' => ['deelnemerslijst', 'urenstaat', 'factuur', 'ander'],
    ];

    /**
     * Default retention (years) per source phase, per Selectielijst 4.x.
     *
     * @var array<string, int>
     */
    public const DEFAULT_BEWAARTERMIJN = [
        'aanvraag'            => 7,
        'tussenrapportage'    => 7,
        'vaststelling'        => 10,
        'verplichtingsbewijs' => 7,
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Schema/register bridge.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a bewijsstuk type is allowed for a source phase (REQ-SUB-007).
     *
     * @param string $gekoppeldAan The source phase.
     * @param string $type         The bewijsstuk type.
     *
     * @return bool True when the combination is on the whitelist.
     */
    public function isTypeAllowed(string $gekoppeldAan, string $type): bool
    {
        $allowed = self::TYPE_WHITELIST[$gekoppeldAan] ?? null;
        if ($allowed === null) {
            return false;
        }

        return in_array($type, $allowed, true);
    }//end isTypeAllowed()

    /**
     * Resolve the retention years for a source phase, preferring a
     * regeling-configured override (REQ-SUB-007).
     *
     * @param string   $gekoppeldAan The source phase.
     * @param int|null $override     Regeling-configured retention, if any.
     *
     * @return int The retention years.
     */
    public function bewaartermijnJaren(string $gekoppeldAan, ?int $override=null): int
    {
        if ($override !== null && $override > 0) {
            return $override;
        }

        return (self::DEFAULT_BEWAARTERMIJN[$gekoppeldAan] ?? 7);
    }//end bewaartermijnJaren()

    /**
     * Compute the retention end date.
     *
     * @param DateTimeImmutable $vanaf The reference date.
     * @param int               $jaren The retention years.
     *
     * @return DateTimeImmutable The retention end date.
     */
    public function bewaartermijnEinde(DateTimeImmutable $vanaf, int $jaren): DateTimeImmutable
    {
        return $vanaf->add(new DateInterval('P'.max(1, $jaren).'Y'));
    }//end bewaartermijnEinde()

    /**
     * Compute the SHA-256 hash of file contents (REQ-SUB-007).
     *
     * @param string $contents The raw file contents.
     *
     * @return string The lowercase hex digest.
     */
    public function computeHash(string $contents): string
    {
        return hash('sha256', $contents);
    }//end computeHash()

    /**
     * Verify file contents against a recorded hash (REQ-SUB-007).
     *
     * @param string $contents     The raw file contents.
     * @param string $expectedHash The recorded digest.
     *
     * @return bool True when the hash matches (constant-time compare).
     */
    public function verifyHash(string $contents, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->computeHash(contents: $contents));
    }//end verifyHash()

    /**
     * Create a bewijsstuk with type validation, retention assignment and a
     * content hash (REQ-SUB-007).
     *
     * @param array<string, mixed> $payload          The bewijsstuk metadata.
     * @param string|null          $contents         Raw file contents to hash, if available.
     * @param int|null             $regelingRetentie Regeling-configured retention override.
     *
     * @return array<string, mixed> The created bewijsstuk record.
     *
     * @throws OCSBadRequestException When validation/persistence fails.
     */
    public function create(array $payload, ?string $contents=null, ?int $regelingRetentie=null): array
    {
        $gekoppeldAan = (string) ($payload['gekoppeldAan'] ?? '');
        $type         = (string) ($payload['bewijsstukType'] ?? '');
        if ($this->isTypeAllowed(gekoppeldAan: $gekoppeldAan, type: $type) === false) {
            throw new OCSBadRequestException('Bewijsstuktype "'.$type.'" is niet toegestaan voor fase "'.$gekoppeldAan.'"');
        }

        [$objectService, $register, $schema] = $this->resolve();

        $now    = new DateTimeImmutable();
        $jaren  = $this->bewaartermijnJaren(gekoppeldAan: $gekoppeldAan, override: $regelingRetentie);
        $record = array_merge(
            $payload,
            [
                'bewaartermijnJaren' => $jaren,
                'bewaartermijnEinde' => $this->bewaartermijnEinde(vanaf: $now, jaren: $jaren)->format('Y-m-d'),
                'archiefStatus'      => 'actief',
                'immutable'          => ($gekoppeldAan === 'vaststelling'),
            ]
        );
        if ($contents !== null) {
            $record['bestandHashSha256'] = $this->computeHash(contents: $contents);
        }

        try {
            return $objectService->saveObject($register, $schema, $record);
        } catch (Throwable $e) {
            $this->logger->error('Procest subsidie: bewijsstuk create failed: '.$e->getMessage());
            throw new OCSBadRequestException('Kon bewijsstuk niet opslaan');
        }
    }//end create()

    /**
     * Guard against mutating/deleting a bewijsstuk linked to a vaststelling
     * (REQ-SUB-007 immutability).
     *
     * @param array<string, mixed> $bewijsstuk The bewijsstuk record.
     *
     * @return void
     *
     * @throws OCSBadRequestException When the document is immutable.
     */
    public function assertMutable(array $bewijsstuk): void
    {
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
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('bewijsstuk_schema');
        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Bewijsstuk-schema is niet geconfigureerd');
        }

        return [$objectService, $register, $schema];
    }//end resolve()
}//end class
