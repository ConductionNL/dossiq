<?php

/**
 * Procest Contract Renewal Service
 *
 * Scans supplier contracts approaching expiry, flags them, and orchestrates
 * supplier-initiated renewal requests (which spawn a Procest
 * `leverancier-contractverlenging-verzoek` case).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contract renewal helper.
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
 */
class ContractRenewalService
{
    /**
     * Warning window — number of days before contract end when we flag it.
     */
    public const RENEWAL_WARNING_DAYS = 90;

    /**
     * Constructor.
     *
     * @param SupplierScopeService    $scopeService Scope helper.
     * @param TenantAuditTrailService $auditTrail   Audit trail emitter.
     * @param IAppManager             $appManager   App manager.
     * @param ContainerInterface      $container    Service container (resolves OR).
     * @param LoggerInterface         $logger       Logger.
     */
    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly TenantAuditTrailService $auditTrail,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute days until expiry. Negative = already expired.
     *
     * @param string $endDate ISO end date.
     * @param int    $nowTs   Reference timestamp.
     *
     * @return int|null Days, or null when endDate is malformed.
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function daysUntilExpiry(string $endDate, int $nowTs): ?int
    {
        $end = strtotime($endDate);
        if ($end === false) {
            return null;
        }

        return (int) floor(($end - $nowTs) / 86400);
    }//end daysUntilExpiry()

    /**
     * Whether a contract is inside the renewal window.
     *
     * @param array<string,mixed> $contract Contract row.
     * @param int                 $nowTs    Reference timestamp.
     *
     * @return bool
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function isWithinRenewalWindow(array $contract, int $nowTs): bool
    {
        $days = $this->daysUntilExpiry(endDate: (string) ($contract['endDate'] ?? ''), nowTs: $nowTs);
        if ($days === null) {
            return false;
        }

        return $days >= 0 && $days <= self::RENEWAL_WARNING_DAYS;
    }//end isWithinRenewalWindow()

    /**
     * Scan all contracts and set renewalWarning on rows entering the window.
     *
     * @param array<int, array<string,mixed>> $contracts Contracts.
     * @param int                             $nowTs     Reference timestamp.
     *
     * @return array<int, array<string,mixed>> Contracts with `renewalWarning` set.
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function scanExpiringContracts(array $contracts, int $nowTs): array
    {
        $out = [];
        foreach ($contracts as $c) {
            if ($this->isWithinRenewalWindow(contract: $c, nowTs: $nowTs) === true && ($c['renewalWarning'] ?? false) === false) {
                $c['renewalWarning'] = true;
                $out[] = $c;
            }
        }

        return $out;
    }//end scanExpiringContracts()

    /**
     * Scan every supplier contract in OpenRegister, persisting `renewalWarning`
     * on rows that have newly entered the 90-day window. Idempotent — rows that
     * are already flagged are skipped by {@see scanExpiringContracts()}, so a
     * second run in the same window writes nothing.
     *
     * Drives the nightly {@see \OCA\Procest\BackgroundJob\ScanExpiringContractsJob}.
     *
     * @param int $nowTs Reference timestamp.
     *
     * @return array{scanned:int, flagged:int} Scan counts.
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function scanAndFlagExpiring(int $nowTs): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['scanned' => 0, 'flagged' => 0];
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: 'supplierContract',
                limit: 1000,
                offset: 0,
                filters: []
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: contract expiry scan list failed', ['exception' => $e->getMessage()]);
            return ['scanned' => 0, 'flagged' => 0];
        }

        $contracts = [];
        if (is_array($rows) === true) {
            $contracts = array_values($rows);
        }

        $toFlag = $this->scanExpiringContracts(contracts: $contracts, nowTs: $nowTs);

        $flagged = 0;
        foreach ($toFlag as $contract) {
            $uuid = (string) ($contract['uuid'] ?? $contract['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            // Persist the full row with renewalWarning flipped (OR update path
            // is saveObject with the merged row + uuid — same pattern as the
            // master-data mutation service).
            $row = array_merge($contract, ['renewalWarning' => true]);
            try {
                $os->saveObject(
                    object: $row,
                    register: TenantSaasService::REGISTER,
                    schema: 'supplierContract',
                    uuid: $uuid
                );
                $flagged++;
            } catch (Throwable $e) {
                $this->logger->error(
                        'Procest: contract renewalWarning persist failed',
                        [
                            'contract'  => $uuid,
                            'exception' => $e->getMessage(),
                        ]
                        );
            }
        }//end foreach

        return ['scanned' => count($contracts), 'flagged' => $flagged];
    }//end scanAndFlagExpiring()

    /**
     * Whether a role is authorised to request a renewal.
     *
     * @param string $role Supplier-side role.
     *
     * @return bool
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function canRequestRenewal(string $role): bool
    {
        return in_array($role, ['admin', 'contracts'], true);
    }//end canRequestRenewal()

    /**
     * Request a renewal. Creates a Procest case + audit-logs the request.
     *
     * @param array<string,mixed> $contract Contract row.
     * @param string              $actor    Requesting NC / supplier user.
     *
     * @return array{ok: bool, caseRef?: string, reason?: string}
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/tasks.md
     */
    public function requestRenewal(array $contract, string $actor): array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return ['ok' => false, 'reason' => 'OpenRegister unavailable'];
        }

        $endDate = (string) ($contract['endDate'] ?? '');
        if ($endDate === '') {
            return ['ok' => false, 'reason' => 'Contract has no end date'];
        }

        $payload = [
            'caseTypeSlug' => 'leverancier-contractverlenging-verzoek',
            'title'        => 'Verlengingsverzoek voor contract '.((string) ($contract['number'] ?? '')),
            'supplierRef'  => (string) ($contract['supplierRef'] ?? ''),
            'contractRef'  => (string) ($contract['uuid'] ?? $contract['id'] ?? ''),
            'submittedBy'  => $actor,
            'submittedAt'  => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        try {
            $row = $os->saveObject(
                object: $payload,
                register: TenantSaasService::REGISTER,
                schema: 'case',
                uuid: null
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: requestRenewal case create failed', ['exception' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'Case create failed'];
        }

        $caseRef = (string) ($row['uuid'] ?? $row['id'] ?? '');
        $this->auditTrail->emit(
                [
                    'action'   => 'contract.renewal_requested',
                    'actor'    => $actor,
                    'resource' => 'contract:'.((string) ($contract['uuid'] ?? '')),
                    'tenantId' => (string) ($contract['supplierRef'] ?? ''),
                ]
                );

        return ['ok' => true, 'caseRef' => $caseRef];
    }//end requestRenewal()

    /**
     * Resolve the OpenRegister ObjectService when the app is installed.
     *
     * @return mixed|null The ObjectService, or null when OR is unavailable.
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }//end getObjectService()
}//end class
