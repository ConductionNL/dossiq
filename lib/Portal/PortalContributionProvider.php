<?php

/**
 * Procest Portal Contribution Provider
 *
 * Procest's contribution to the shared Portaliq external portal (hydra ADR-046).
 * Portaliq discovers this class by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and duck-types it
 * (getAudience + getContribution), so procest does NOT depend on Portaliq — this
 * class references nothing from the portal app and is inert when Portaliq is
 * absent (only Portaliq's registry ever loads it).
 *
 * It declares — for the supplier audience — the OpenRegister collections a
 * supplier may see (tenders, contracts, invoices) and their inbox
 * (supplierMessage), all scoped by `supplierRef`. Portaliq reads them RBAC-scoped
 * to the subject; procest exposes no portal endpoints of its own here.
 *
 * @category Portal
 * @package  OCA\Procest\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/portal-contribution/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Procest\Portal;

/**
 * Declares procest's supplier contribution to the Portaliq portal.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#T1
 */
class PortalContributionProvider
{
    /**
     * The external audience this provider contributes to.
     *
     * @return string
     *
     * @spec openspec/changes/portal-contribution/tasks.md#T1
     */
    public function getAudience(): string
    {
        return 'supplier';
    }//end getAudience()

    /**
     * Describe procest's contribution for the given supplier subject.
     *
     * @param array<string, mixed> $subject The resolved subject (subjectRef =
     *                                      supplierRef, audience, organisation).
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/portal-contribution/tasks.md#T1
     */
    public function getContribution(array $subject): ?array
    {
        if (($subject['audience'] ?? '') !== 'supplier') {
            return null;
        }

        return [
            'label'         => 'Procest',
            'collections'   => [
                [
                    'id'         => 'tenders',
                    'register'   => 'procest',
                    'schema'     => 'supplierTender',
                    'scopeField' => 'supplierRef',
                    'label'      => 'Aanbestedingen',
                    'listable'   => true,
                ],
                [
                    'id'         => 'contracts',
                    'register'   => 'procest',
                    'schema'     => 'supplierContract',
                    'scopeField' => 'supplierRef',
                    'label'      => 'Contracten',
                    'listable'   => true,
                ],
                [
                    'id'         => 'invoices',
                    'register'   => 'procest',
                    'schema'     => 'supplierInvoice',
                    'scopeField' => 'supplierRef',
                    'label'      => 'Facturen',
                    'listable'   => true,
                ],
                [
                    'id'         => 'messages',
                    'kind'       => 'inbox',
                    'register'   => 'procest',
                    'schema'     => 'supplierMessage',
                    'scopeField' => 'supplierRef',
                    'label'      => 'Berichten',
                    'listable'   => true,
                ],
            ],
            'actions'       => [],
            'notifications' => ['tenderPublished', 'contractExpiring', 'invoiceDue'],
        ];
    }//end getContribution()
}//end class
