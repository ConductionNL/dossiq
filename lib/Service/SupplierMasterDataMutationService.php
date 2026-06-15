<?php

/**
 * Procest Supplier Master Data Mutation Service
 *
 * Self-service mutations on supplier master data. Address + contact apply
 * immediately; IBAN goes through a 4-eyes Procest workflow; accreditations
 * go through a verification workflow.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Procest\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Procest\Service\External\Kvk\KvkLookupResult;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class SupplierMasterDataMutationService
{
    use SearchesObjects;

    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly TenantAuditTrailService $auditTrail,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ?KvkHandelsregisterAdapterInterface $kvkAdapter = null,
    ) {
    }

    /**
     * Validate a KvK number against the Handelsregister.
     *
     * Performs a format check (8 digits, leading zeros allowed) and, when a
     * KvK adapter is bound, enriches the result with the entity envelope.
     * A dormant adapter returns `LOOKUP_DEFERRED`; an active binding returns
     * `FOUND`/`NOT_FOUND` with the legal entity payload (statutaireNaam,
     * rsin, vestiging).
     *
     * @param string $kvkNumber 8-digit KvK number.
     * @param string $caseId    Optional zaak id for audit correlation.
     *
     * @return array{
     *     ok: bool,
     *     reason?: string,
     *     status?: string,
     *     entity?: array<string,mixed>,
     *     dormant?: bool,
     *     extras?: array<string,mixed>,
     * }
     *
     * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
     */
    public function validateKvk(string $kvkNumber, string $caseId = ''): array
    {
        $normalised = preg_replace('/\D/', '', $kvkNumber) ?? '';
        if (strlen($normalised) !== 8) {
            return ['ok' => false, 'reason' => 'KvK-nummer moet 8 cijfers bevatten'];
        }

        if ($this->kvkAdapter === null) {
            return [
                'ok'      => true,
                'reason'  => 'KvK-formaat geldig; geen adapter gebonden',
                'status'  => 'FORMAT_ONLY',
                'entity'  => [],
                'dormant' => true,
            ];
        }

        try {
            /** @var KvkLookupResult $result */
            $result = $this->kvkAdapter->lookup(
                $normalised,
                [
                    'lookupReason'  => 'master-data-mutation',
                    'caseId'        => $caseId,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'SupplierMasterDataMutationService.validateKvk adapter lookup failed',
                ['error' => $e->getMessage(), 'kvkNumber' => $normalised]
            );
            return [
                'ok'     => false,
                'reason' => 'KvK Handelsregister lookup mislukt',
                'status' => 'LOOKUP_ERROR',
            ];
        }

        switch ($result->lookupStatus) {
            case 'FOUND':
                return [
                    'ok'      => true,
                    'status'  => 'FOUND',
                    'entity'  => $result->entity,
                    'dormant' => $result->dormant,
                    'extras'  => $result->extras,
                ];
            case 'NOT_FOUND':
                return [
                    'ok'     => false,
                    'reason' => 'KvK-nummer niet gevonden in Handelsregister',
                    'status' => 'NOT_FOUND',
                    'dormant'=> $result->dormant,
                ];
            case 'LOOKUP_DEFERRED':
            default:
                return [
                    'ok'      => true,
                    'reason'  => 'KvK-formaat geldig; Handelsregister lookup uitgesteld',
                    'status'  => $result->lookupStatus,
                    'entity'  => $result->entity,
                    'dormant' => $result->dormant,
                    'extras'  => $result->extras,
                ];
        }
    }

    /**
     * Apply an address update immediately.
     *
     * @param string             $supplierRef Supplier UUID.
     * @param array<string,mixed> $newAddress New address payload.
     * @param string             $actor       NC user / supplier user id.
     *
     * @return array<string,mixed>|null Persisted supplier row.
     */
    public function updateAddress(string $supplierRef, array $newAddress, string $actor): ?array
    {
        return $this->applyImmediate($supplierRef, ['address' => $newAddress], $actor, 'address');
    }

    /**
     * Apply a contact-person update immediately.
     *
     * @param string $supplierRef Supplier UUID.
     * @param string $newContact  New contact name.
     * @param string $actor       Actor id.
     *
     * @return array<string,mixed>|null
     */
    public function updateContactPerson(string $supplierRef, string $newContact, string $actor): ?array
    {
        return $this->applyImmediate($supplierRef, ['contactPerson' => $newContact], $actor, 'contactPerson');
    }

    /**
     * Request an IBAN change. Creates a 4-eyes Procest case and does NOT apply.
     *
     * @param string $supplierRef Supplier UUID.
     * @param string $newIban     Proposed IBAN.
     * @param string $actor       Actor id.
     *
     * @return array{ok:bool, caseRef?:string, reason?:string}
     */
    public function requestIbanChange(string $supplierRef, string $newIban, string $actor): array
    {
        if ($this->isValidIban($newIban) === false) {
            return ['ok' => false, 'reason' => 'Invalid IBAN'];
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return ['ok' => false, 'reason' => 'OpenRegister unavailable'];
        }

        $payload = [
            'caseTypeSlug' => 'leverancier-iban-wijziging',
            'title'        => 'IBAN-wijziging voor leverancier',
            'supplierRef'  => $supplierRef,
            'proposedIban' => $this->scopeService->maskIban($newIban),
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
            return ['ok' => false, 'reason' => 'Case create failed'];
        }

        $this->auditTrail->emit([
            'action'   => 'supplier.iban_change_requested',
            'actor'    => $actor,
            'resource' => 'supplier:'.$supplierRef,
            'tenantId' => $supplierRef,
        ]);

        return ['ok' => true, 'caseRef' => (string) ($row['uuid'] ?? $row['id'] ?? '')];
    }

    /**
     * Submit a master-data change for verification (accreditations, certificates).
     *
     * @param string $supplierRef Supplier UUID.
     * @param string $dataType    Data type ('accreditation', 'sbi', 'kvk', ...).
     * @param array<int, string> $attachments Attachment refs.
     * @param string $actor       Actor id.
     * @param string $kvkNumber   Optional KvK number — when the verification is a
     *                            KvK registration check (dataType 'kvk' or a number
     *                            is supplied) it is validated against the
     *                            Handelsregister before the case is opened.
     *
     * @return array{ok:bool, caseRef?:string, reason?:string}
     *
     * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
     */
    public function submitForVerification(string $supplierRef, string $dataType, array $attachments, string $actor, string $kvkNumber = ''): array
    {
        // Fail closed: a KvK verification with a malformed / unverifiable KvK
        // number must not open a verification case. validateKvk() runs the
        // format check (and, when an adapter is bound, the Handelsregister
        // lookup) and returns ok=false on rejection.
        if ($dataType === 'kvk' || $kvkNumber !== '') {
            $kvkResult = $this->validateKvk($kvkNumber);
            if (($kvkResult['ok'] ?? false) === false) {
                return ['ok' => false, 'reason' => (string) ($kvkResult['reason'] ?? 'KvK-validatie mislukt')];
            }
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return ['ok' => false];
        }

        $payload = [
            'caseTypeSlug' => 'leverancier-accreditatie-verificatie',
            'title'        => 'Verificatie van '.$dataType,
            'supplierRef'  => $supplierRef,
            'dataType'     => $dataType,
            'attachments'  => $attachments,
            'submittedBy'  => $actor,
            'submittedAt'  => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        try {
            $row = $os->saveObject(
                object: $payload,
                register: TenantSaasService::REGISTER,
                schema: 'case',
                uuid: null,
            );
        } catch (Throwable $e) {
            return ['ok' => false];
        }

        $this->auditTrail->emit([
            'action'   => 'supplier.verification_submitted',
            'actor'    => $actor,
            'resource' => 'supplier:'.$supplierRef,
            'tenantId' => $supplierRef,
        ]);
        return ['ok' => true, 'caseRef' => (string) ($row['uuid'] ?? $row['id'] ?? '')];
    }

    /**
     * IBAN format check — uppercase letters/digits, length 15-34, mod-97 check.
     *
     * @param string $iban IBAN.
     *
     * @return bool
     */
    public function isValidIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        // Move first 4 chars to end and convert letters to digits.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric    = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        // mod-97 over a (possibly long) numeric string.
        $remainder = 0;
        foreach (str_split($numeric) as $d) {
            $remainder = ((($remainder * 10) + (int) $d) % 97);
        }

        return $remainder === 1;
    }

    /**
     * @param string             $supplierRef Supplier UUID.
     * @param array<string,mixed> $delta      Fields to merge.
     * @param string             $actor       Actor id.
     * @param string             $auditTag    Audit-log subtype.
     *
     * @return array<string,mixed>|null
     */
    private function applyImmediate(string $supplierRef, array $delta, string $actor, string $auditTag): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $row = $this->findObjectAsArray(objectService: $os, register: TenantSaasService::REGISTER, schema: 'supplier', id: $supplierRef);
        } catch (Throwable $e) {
            return null;
        }

        if (is_array($row) === false) {
            return null;
        }

        $row = array_merge($row, $delta);
        try {
            $persisted = $os->saveObject(
                object: $row,
                register: TenantSaasService::REGISTER,
                schema: 'supplier',
                uuid: $supplierRef,
            );
            $this->auditTrail->emit([
                'action'   => 'supplier.'.$auditTag.'_update',
                'actor'    => $actor,
                'resource' => 'supplier:'.$supplierRef,
                'tenantId' => $supplierRef,
            ]);
            return $persisted;
        } catch (Throwable $e) {
            return null;
        }
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
