<?php

/**
 * Procest Checklist Service
 *
 * Service for managing inspection checklists: item completion with
 * conformity tracking, mandatory photo validation, and progress monitoring.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for managing inspection checklists.
 *
 * Handles checklist item completion with conformity status tracking,
 * mandatory photo validation for non-conformities, and progress monitoring.
 *
 * @psalm-suppress UnusedClass
 */
class ChecklistService
{
    /**
     * Conformity status: conform.
     */
    public const STATUS_CONFORM = 'conform';

    /**
     * Conformity status: niet-conform (non-conformity).
     */
    public const STATUS_NIET_CONFORM = 'niet_conform';

    /**
     * Conformity status: not applicable.
     */
    public const STATUS_NVT = 'niet_van_toepassing';

    /**
     * Valid conformity statuses.
     *
     * @var string[]
     */
    public const VALID_STATUSES = [
        self::STATUS_CONFORM,
        self::STATUS_NIET_CONFORM,
        self::STATUS_NVT,
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Complete a checklist item with a conformity status.
     *
     * @param array<string, mixed> $checklist   The checklist data.
     * @param string               $itemId      The checklist item ID.
     * @param string               $status      The conformity status.
     * @param string               $toelichting Free-text explanation.
     * @param string[]             $photoRefs   Photo file references (required for niet-conform if configured).
     *
     * @return array<string, mixed> The updated checklist.
     *
     * @throws \InvalidArgumentException If status is invalid or mandatory photo is missing.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function completeItem(
        array $checklist,
        string $itemId,
        string $status,
        string $toelichting='',
        array $photoRefs=[],
    ): array {
        if (in_array($status, self::VALID_STATUSES, true) === false) {
            throw new \InvalidArgumentException(
                'Invalid conformity status: '.$status.'. Valid: '.implode(', ', self::VALID_STATUSES)
            );
        }

        $items     = $checklist['items'] ?? [];
        $itemFound = false;

        foreach ($items as $index => $item) {
            if (($item['id'] ?? '') === $itemId) {
                // Check mandatory photo for niet-conform.
                $requiresPhoto = $item['fotoVerplichtBijNietConform'] ?? false;
                if ($status === self::STATUS_NIET_CONFORM && $requiresPhoto === true && empty($photoRefs) === true) {
                    throw new \InvalidArgumentException(
                        'Foto verplicht bij niet-conform voor item: '.($item['description'] ?? $itemId)
                    );
                }

                $items[$index]['status']      = $status;
                $items[$index]['toelichting'] = $toelichting;
                $items[$index]['photoRefs']   = $photoRefs;
                $items[$index]['completedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $itemFound = true;
                break;
            }
        }

        if ($itemFound === false) {
            throw new \InvalidArgumentException('Checklist item not found: '.$itemId);
        }

        $checklist['items'] = $items;

        $this->logger->info(
            'Checklist item {itemId} completed with status {status}',
            ['itemId' => $itemId, 'status' => $status]
        );

        return $checklist;
    }//end completeItem()

    /**
     * Get the completion progress of a checklist.
     *
     * @param array<string, mixed> $checklist The checklist data.
     *
     * @return array{completed: int, total: int, percentage: float}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getProgress(array $checklist): array
    {
        $items     = $checklist['items'] ?? [];
        $total     = count($items);
        $completed = 0;

        foreach ($items as $item) {
            if (empty($item['status']) === false) {
                $completed++;
            }
        }

        if ($total > 0) {
            $percentage = round(($completed / $total) * 100, 1);
        } else {
            $percentage = 0.0;
        }

        return [
            'completed'  => $completed,
            'total'      => $total,
            'percentage' => $percentage,
        ];
    }//end getProgress()

    /**
     * Validate that all checklist items are completed.
     *
     * @param array<string, mixed> $checklist The checklist data.
     *
     * @return array{valid: bool, missingItems: string[]}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function validateCompletion(array $checklist): array
    {
        $items        = $checklist['items'] ?? [];
        $missingItems = [];

        foreach ($items as $item) {
            if (empty($item['status']) === true) {
                $missingItems[] = $item['description'] ?? ($item['id'] ?? 'unknown');
            }
        }

        return [
            'valid'        => empty($missingItems),
            'missingItems' => $missingItems,
        ];
    }//end validateCompletion()

    /**
     * Get a summary of conformity results.
     *
     * @param array<string, mixed> $checklist The checklist data.
     *
     * @return array{conform: int, nietConform: int, nvt: int, notCompleted: int}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function getConformitySummary(array $checklist): array
    {
        $items   = $checklist['items'] ?? [];
        $summary = [
            'conform'      => 0,
            'nietConform'  => 0,
            'nvt'          => 0,
            'notCompleted' => 0,
        ];

        foreach ($items as $item) {
            $status = $item['status'] ?? '';
            match ($status) {
                self::STATUS_CONFORM => $summary['conform']++,
                self::STATUS_NIET_CONFORM => $summary['nietConform']++,
                self::STATUS_NVT => $summary['nvt']++,
                default => $summary['notCompleted']++,
            };
        }

        return $summary;
    }//end getConformitySummary()
}//end class
