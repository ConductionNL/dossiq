<?php

/**
 * Procest TermijnPauseService.
 *
 * AWB 4:5 / 4:15 hersteltermijn pause + resume on a TermijnInstance.
 * Pausing extends einddatumActueel by the requested duration in days and
 * flips status to gepauzeerd. Resuming after an aanvulling consumes the
 * elapsed pause days and re-extends einddatumActueel by the *unconsumed*
 * pause days only.
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
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use RuntimeException;

/**
 * AWB 4:5 / 4:15 pause + resume on a TermijnInstance.
 */
class TermijnPauseService
{
    /**
     * Constructor.
     *
     * @param TermijnService $termijnService TermijnService.
     */
    public function __construct(
        private readonly TermijnService $termijnService,
    ) {
    }//end __construct()

    /**
     * Register a pauze on a TermijnInstance.
     *
     * Extends einddatumActueel by `duurDagen`, sets status=gepauzeerd,
     * records a `pauze` event with dagenImpact=+duurDagen, and stores
     * the pause deadline for the daily scan to watch.
     *
     * @param string $termijnInstanceId Instance id.
     * @param int    $duurDagen         Pause days requested.
     * @param string $motivering        Reason.
     * @param string $documentLink      Document link (e.g. hersteltermijnbrief).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When instance missing or duurDagen <= 0.
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
     */
    public function registerPauze(
        string $termijnInstanceId,
        int $duurDagen,
        string $motivering,
        string $documentLink=''
    ): array {
        if ($duurDagen <= 0) {
            throw new RuntimeException('Pause duration must be positive (AWB 4:5)');
        }

        $instance = $this->termijnService->getTermijnInstance($termijnInstanceId);
        if ($instance === null) {
            throw new RuntimeException('TermijnInstance not found: '.$termijnInstanceId);
        }

        if (($instance['status'] ?? '') === 'gepauzeerd') {
            throw new RuntimeException('TermijnInstance already paused: '.$termijnInstanceId);
        }

        $now      = new DateTimeImmutable();
        $current  = new DateTimeImmutable((string) ($instance['einddatumActueel'] ?? $now->format('Y-m-d')));
        $newEnd   = $current->modify('+'.$duurDagen.' days')->format('Y-m-d');
        $pauseEnd = $now->modify('+'.$duurDagen.' days')->format('Y-m-d');

        $updated = $this->termijnService->updateTermijnInstance(
            $termijnInstanceId,
            [
                'einddatumActueel' => $newEnd,
                'status'           => 'gepauzeerd',
                'pauzeDeadline'    => $pauseEnd,
                'pauzeStartDatum'  => $now->format('Y-m-d'),
                'pauzeDuurDagen'   => $duurDagen,
            ]
        );

        $this->termijnService->recordEvent(
            termijnInstanceId: $termijnInstanceId,
            type: 'pauze',
            grondslag: 'AWB 4:5',
            motivering: $motivering,
            dagenImpact: $duurDagen,
            tijdstip: $now,
            documentLink: $documentLink,
        );

        return $updated ?? $instance;
    }//end registerPauze()

    /**
     * Resume after pauze with the aanvulling-datum.
     *
     * Computes consumed vs. unconsumed pause days; adds only the
     * unconsumed portion to einddatumActueel; sets status=lopend and
     * records the `hervat` event.
     *
     * @param string                 $termijnInstanceId Instance id.
     * @param DateTimeImmutable|null $aanvullingDatum   When aanvulling received (default now).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When instance missing or not paused.
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
     */
    public function resumeAfterPauze(string $termijnInstanceId, ?DateTimeImmutable $aanvullingDatum=null): array
    {
        $aanvullingDatum = ($aanvullingDatum ?? new DateTimeImmutable());

        $instance = $this->termijnService->getTermijnInstance($termijnInstanceId);
        if ($instance === null) {
            throw new RuntimeException('TermijnInstance not found: '.$termijnInstanceId);
        }

        if (($instance['status'] ?? '') !== 'gepauzeerd') {
            throw new RuntimeException('TermijnInstance not in gepauzeerd state: '.$termijnInstanceId);
        }

        $pauzeStart = new DateTimeImmutable((string) ($instance['pauzeStartDatum'] ?? $aanvullingDatum->format('Y-m-d')));
        $duurDagen  = (int) ($instance['pauzeDuurDagen'] ?? 0);

        // Days actually used (cap at the requested duration).
        $diff     = (int) $pauzeStart->diff($aanvullingDatum)->days;
        $consumed = max(0, min($duurDagen, $diff));
        $unused   = $duurDagen - $consumed;

        // Pull back the unused portion of einddatumActueel.
        $current = new DateTimeImmutable((string) ($instance['einddatumActueel'] ?? $aanvullingDatum->format('Y-m-d')));
        $newEnd  = $current->modify('-'.$unused.' days')->format('Y-m-d');

        $updated = $this->termijnService->updateTermijnInstance(
            $termijnInstanceId,
            [
                'einddatumActueel' => $newEnd,
                'status'           => 'lopend',
                'pauzeDeadline'    => null,
            ]
        );

        $this->termijnService->recordEvent(
            termijnInstanceId: $termijnInstanceId,
            type: 'hervat',
            grondslag: 'AWB 4:15',
            motivering: 'Aanvulling ontvangen; termijn hervat',
            dagenImpact: (-1 * $unused),
            tijdstip: $aanvullingDatum,
        );

        return $updated ?? $instance;
    }//end resumeAfterPauze()
}//end class
