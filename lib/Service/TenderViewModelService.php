<?php

/**
 * Procest Tender View-Model Service
 *
 * Server-side view-model for the supplier-portal tender list / detail pages.
 * Centralises the badge-colour map, the visibility rules per status, and
 * the cache TTL so the Vue components stay stateless presentation layers.
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
 * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Tender view-model helpers (status badges, visibility decisions, cache hints).
 */
class TenderViewModelService
{
    /**
     * Status → NL Design System badge colour token. Vue components consume
     * this directly so no front-end if/else logic decides theming.
     *
     * @var array<string, string>
     */
    public const STATUS_BADGE_COLORS = [
        'submitted'  => 'gray',
        'evaluating' => 'blue',
        'awarded'    => 'green',
        'rejected'   => 'red',
        'withdrawn'  => 'orange',
    ];

    /**
     * Cache TTL (seconds) — surfaced as a Cache-Control: max-age hint.
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Resolve the badge colour for a tender status.
     *
     * @param string $status Tender status.
     *
     * @return string Colour token.
     */
    public function badgeColor(string $status): string
    {
        return self::STATUS_BADGE_COLORS[$status] ?? 'gray';
    }//end badgeColor()

    /**
     * Return the sections the detail page should render for a tender row.
     *
     * @param array<string,mixed> $tender Tender row.
     *
     * @return array{showAward:bool, showRejection:bool, showWithdrawal:bool, showEvaluationDownload:bool}
     */
    public function visibilityFlags(array $tender): array
    {
        $status  = (string) ($tender['status'] ?? '');
        $hasEval = (string) ($tender['evaluationReportRef'] ?? '') !== '';

        return [
            'showAward'              => $status === 'awarded',
            'showRejection'          => $status === 'rejected',
            'showWithdrawal'         => $status === 'withdrawn',
            'showEvaluationDownload' => in_array($status, ['awarded', 'rejected'], true) && $hasEval,
        ];
    }//end visibilityFlags()

    /**
     * Cache-Control hint.
     *
     * @return string
     */
    public function cacheControlHeader(): string
    {
        return 'private, max-age='.self::CACHE_TTL_SECONDS;
    }//end cacheControlHeader()
}//end class
