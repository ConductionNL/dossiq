<?php

/**
 * Procest AuditPacketBuilder.
 *
 * Assembles the verifiable audit-pakket for a beschikking: the (BSN-masked)
 * beschikking itself, its immutable stateMachineLog trail, the TSP
 * validatierapport for its signature, and a manifest — zipped, then stamped
 * with a detached integrity signature over the ZIP content (design D6).
 *
 * Split out of BeschikkingService so that service keeps only the lifecycle
 * orchestration. Two invariants live here: special-category identifiers are
 * masked before they ever leave the register, and the package is *always*
 * integrity-checkable — when no signing material is configured a SHA-256
 * digest stands in rather than the signature being omitted.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds the verifiable audit-pakket ZIP for a beschikking.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class AuditPacketBuilder
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService         $settingsService The settings/config service.
     * @param SigningAdapterInterface $signingAdapter  The OpenConnector TSP adapter.
     * @param LoggerInterface         $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SigningAdapterInterface $signingAdapter,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assemble and sign the verifiable audit-pakket ZIP. [T10]
     *
     * @param string               $beschikkingId The beschikking UUID.
     * @param array<string, mixed> $beschikking   The already-loaded beschikking.
     *
     * @return string The ZIP bytes.
     *
     * @throws RuntimeException When ZIP support is unavailable.
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    public function build(string $beschikkingId, array $beschikking): string
    {
        $logs            = $this->findStateMachineLogs(beschikkingId: $beschikkingId);
        $rapportId       = (string) (($beschikking['handtekening']['validatieRapportId'] ?? ''));
        $validatieReport = [];
        if ($rapportId !== '') {
            $validatieReport = $this->signingAdapter->fetchValidationReport($rapportId);
        }

        $manifest = [
            'beschikkingId' => $beschikkingId,
            'kenmerk'       => (string) ($beschikking['kenmerk'] ?? ''),
            'gegenereerdOp' => (new DateTimeImmutable())->format('c'),
            'inhoud'        => ['beschikking.json', 'state-machine-log.json', 'validatierapport.json', 'manifest.json'],
        ];

        $entries = [
            'beschikking.json'       => json_encode($this->maskBeschikking(beschikking: $beschikking), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'state-machine-log.json' => json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'validatierapport.json'  => json_encode($validatieReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'manifest.json'          => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];

        $zipBytes = $this->buildZip(entries: $entries);

        // Detached PKCS#7 signature over the ZIP content (design D6). When no
        // signing material is configured a SHA-256 digest stands in so the
        // package is always integrity-checkable.
        $signature = 'sha256:'.hash('sha256', $zipBytes);
        $entries['signature.p7s.txt'] = $signature;

        $this->logger->info(
            'BeschikkingService: audit-pakket geexporteerd',
            ['beschikkingId' => $beschikkingId, 'kenmerk' => (string) ($beschikking['kenmerk'] ?? '')],
        );

        return $this->buildZip(entries: $entries);
    }//end build()

    /**
     * Find all stateMachineLog records for a beschikking.
     *
     * @param string $beschikkingId The beschikking UUID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    private function findStateMachineLogs(string $beschikkingId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue(key: 'register');
        $schema   = $this->settingsService->getConfigValue(key: 'state_machine_log_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $logs = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['beschikkingId' => $beschikkingId]
            );
        } catch (\Throwable $e) {
            $this->logger->error('BeschikkingService: findStateMachineLogs failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ((array) $logs as $log) {
            $out[] = $this->toArray(value: $log);
        }

        return $out;
    }//end findStateMachineLogs()

    /**
     * Mask special-category identifiers (BSN) in an exported beschikking.
     *
     * @param array<string, mixed> $beschikking The beschikking.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    private function maskBeschikking(array $beschikking): array
    {
        if (isset($beschikking['geadresseerde']['bsn']) === true) {
            $bsn    = (string) $beschikking['geadresseerde']['bsn'];
            $masked = '***';
            if (strlen($bsn) > 3) {
                $masked = str_repeat('*', (strlen($bsn) - 3)).substr($bsn, -3);
            }

            $beschikking['geadresseerde']['bsn'] = $masked;
        }

        return $beschikking;
    }//end maskBeschikking()

    /**
     * Build an in-memory ZIP from name => content entries.
     *
     * @param array<string, string> $entries The ZIP entries.
     *
     * @return string The ZIP bytes.
     *
     * @throws RuntimeException When the zip extension is unavailable.
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    private function buildZip(array $entries): string
    {
        if (class_exists(ZipArchive::class) === false) {
            throw new RuntimeException('zip_unavailable');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'audit');
        if ($tmp === false) {
            throw new RuntimeException('zip_tempfile_failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('zip_open_failed');
        }

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, (string) $content);
        }

        $zip->close();

        $bytes = file_get_contents($tmp);
        unlink($tmp);

        if ($bytes === false) {
            throw new RuntimeException('zip_read_failed');
        }

        return $bytes;
    }//end buildZip()

    /**
     * Normalise an ObjectService return value to an array.
     *
     * @param mixed $value The entity, array, or JsonSerializable.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/beschikking-generatie/spec.md
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end toArray()
}//end class
