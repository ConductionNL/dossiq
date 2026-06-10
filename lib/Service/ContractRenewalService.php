<?php

/**
 * Procest Contract Renewal Service
 *
 * Scans supplier contracts approaching expiry, flags them, and orchestrates
 * supplier-initiated renewal requests (which spawn a Procest
 * `leverancier-contractverlenging-verzoek` case).
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 */
class ContractRenewalService
{
    /**
     * Warning window — number of days before contract end when we flag it.
     */
    public const RENEWAL_WARNING_DAYS = 90;

    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly TenantAuditTrailService $auditTrail,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute days until expiry. Negative = already expired.
     *
     * @param string $endDate ISO end date.
     * @param int    $nowTs   Reference timestamp.
     *
     * @return int|null Days, or null when endDate is malformed.
     */
    public function daysUntilExpiry(string $endDate, int $nowTs): ?int
    {
        $end = strtotime($endDate);
        if ($end === false) {
            return null;
        }

        return (int) floor(($end - $nowTs) / 86400);
    }

    /**
     * Whether a contract is inside the renewal window.
     *
     * @param array<string,mixed> $contract Contract row.
     * @param int                 $nowTs    Reference timestamp.
     *
     * @return bool
     */
    public function isWithinRenewalWindow(array $contract, int $nowTs): bool
    {
        $days = $this->daysUntilExpiry((string) ($contract['endDate'] ?? ''), $nowTs);
        if ($days === null) {
            return false;
        }

        return $days >= 0 && $days <= self::RENEWAL_WARNING_DAYS;
    }

    /**
     * Scan all contracts and set renewalWarning on rows entering the window.
     *
     * @param array<int, array<string,mixed>> $contracts Contracts.
     * @param int                             $nowTs     Reference timestamp.
     *
     * @return array<int, array<string,mixed>> Contracts with `renewalWarning` set.
     */
    public function scanExpiringContracts(array $contracts, int $nowTs): array
    {
        $out = [];
        foreach ($contracts as $c) {
            if ($this->isWithinRenewalWindow($c, $nowTs) === true && ($c['renewalWarning'] ?? false) === false) {
                $c['renewalWarning'] = true;
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * Whether a role is authorised to request a renewal.
     *
     * @param string $role Supplier-side role.
     *
     * @return bool
     */
    public function canRequestRenewal(string $role): bool
    {
        return in_array($role, ['admin', 'contracts'], true);
    }

    /**
     * Request a renewal. Creates a Procest case + audit-logs the request.
     *
     * @param array<string,mixed> $contract Contract row.
     * @param string              $actor    Requesting NC / supplier user.
     *
     * @return array{ok: bool, caseRef?: string, reason?: string}
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
            'caseTypeSlug'  => 'leverancier-contractverlenging-verzoek',
            'title'         => 'Verlengingsverzoek voor contract '.((string) ($contract['number'] ?? '')),
            'supplierRef'   => (string) ($contract['supplierRef'] ?? ''),
            'contractRef'   => (string) ($contract['uuid'] ?? $contract['id'] ?? ''),
            'submittedBy'   => $actor,
            'submittedAt'   => (new DateTimeImmutable('now'))->format(DATE_ATOM),
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
        $this->auditTrail->emit([
            'action'   => 'contract.renewal_requested',
            'actor'    => $actor,
            'resource' => 'contract:'.((string) ($contract['uuid'] ?? '')),
            'tenantId' => (string) ($contract['supplierRef'] ?? ''),
        ]);

        return ['ok' => true, 'caseRef' => $caseRef];
    }

    /**
     * @return mixed|null
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
    }
}
