<?php

/**
 * Procest Tender Visibility Service
 *
 * Supplier-portal tender lookup + appeal-deadline math + evaluation/award
 * document gating.
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
 * @spec openspec/changes/leverancier-zaakportaal-05-tender-backend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tender visibility service.
 */
class TenderVisibilityService
{
    use SearchesObjects;

    /**
     * Appeal window in calendar days after rejection.
     */
    public const APPEAL_WINDOW_DAYS = 20;

    public function __construct(
        private readonly SupplierScopeService $scopeService,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get tender with derived fields. Returns null when the tender is
     * out of the caller's scope.
     *
     * @param string $tenderId    Tender UUID.
     * @param string $supplierRef Current supplier ref.
     *
     * @return array<string,mixed>|null
     */
    public function getTenderStatus(string $tenderId, string $supplierRef): ?array
    {
        $os = $this->getObjectService();
        if ($os === null) {
            return null;
        }

        try {
            $row = $this->findObjectAsArray($os, TenantSaasService::REGISTER, 'supplierTender', $tenderId);
        } catch (Throwable $e) {
            return null;
        }

        if (is_array($row) === false) {
            return null;
        }

        if ($this->scopeService->validateSupplierAccess($row, $supplierRef) === false) {
            return null;
        }

        $row['_derived'] = [
            'appealDeadline' => $this->getAppealDeadline($row),
            'canAppeal'      => $this->canAppeal($row),
            'evaluationDownloadable' => $this->isEvaluationReportDownloadable($row),
        ];
        return $row;
    }

    /**
     * Compute the appeal deadline for a rejected tender. Returns null for
     * non-rejected tenders.
     *
     * @param array<string,mixed> $tender Tender row.
     *
     * @return string|null ISO-8601 date.
     */
    public function getAppealDeadline(array $tender): ?string
    {
        if ((string) ($tender['status'] ?? '') !== 'rejected') {
            return null;
        }

        $explicit = (string) ($tender['appealDeadline'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $awardOrReject = strtotime((string) ($tender['awardDate'] ?? $tender['submittedDate'] ?? 'now'));
        if ($awardOrReject === false) {
            return null;
        }

        return (new DateTimeImmutable('@'.$awardOrReject))->modify('+'.self::APPEAL_WINDOW_DAYS.' days')->format('Y-m-d');
    }

    /**
     * Whether the supplier can still appeal (rejected + within window).
     *
     * @param array<string,mixed> $tender Tender row.
     *
     * @return bool
     */
    public function canAppeal(array $tender): bool
    {
        $deadline = $this->getAppealDeadline($tender);
        if ($deadline === null) {
            return false;
        }

        $dt = strtotime($deadline);
        return $dt !== false && $dt >= time();
    }

    /**
     * Whether the anonymised evaluation report can be downloaded.
     *
     * @param array<string,mixed> $tender Tender row.
     *
     * @return bool
     */
    public function isEvaluationReportDownloadable(array $tender): bool
    {
        $status = (string) ($tender['status'] ?? '');
        if (in_array($status, ['rejected', 'awarded'], true) === false) {
            return false;
        }

        return (string) ($tender['evaluationReportRef'] ?? '') !== '';
    }

    /**
     * @param string $tenderId    Tender UUID.
     * @param string $supplierRef Caller's supplier.
     *
     * @return string|null Evaluation report ref or null.
     */
    public function getEvaluationReport(string $tenderId, string $supplierRef): ?string
    {
        $tender = $this->getTenderStatus($tenderId, $supplierRef);
        if ($tender === null || $this->isEvaluationReportDownloadable($tender) === false) {
            return null;
        }

        $this->logger->info('Procest: supplier downloaded evaluation report', ['supplierRef' => $supplierRef, 'tenderId' => $tenderId]);
        return (string) $tender['evaluationReportRef'];
    }

    /**
     * List tenders for the caller, optionally filtered by status.
     *
     * @param string      $supplierRef Caller's supplier.
     * @param string|null $status      Optional status filter.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listTenders(string $supplierRef, ?string $status=null): array
    {
        $extra = [];
        if ($status !== null && $status !== '') {
            $extra['status'] = $status;
        }

        return $this->scopeService->listSupplierObjects(
            supplierRef: $supplierRef,
            schema: 'supplierTender',
            extraFilters: $extra
        );
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
