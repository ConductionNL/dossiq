<?php

/**
 * Procest Action Result value object.
 *
 * Carries the outcome of a dispatched automatic action: success flag,
 * optional static error message (never leak exception detail), and
 * action-specific result data.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Procest\Service\Transitions;

/**
 * Immutable value object returned by every ActionHandler.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T07
 */
final class ActionResult
{
    /**
     * Constructor.
     *
     * @param bool                 $ok    Whether the action succeeded
     * @param string|null          $error Static error message (no exception detail)
     * @param array<string, mixed> $data  Optional structured data from the action
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error=null,
        public readonly array $data=[],
    ) {
    }//end __construct()

    /**
     * Convenience constructor for a successful result.
     *
     * @param array<string, mixed> $data Optional result data
     *
     * @return self
     */
    /** @spec openspec/specs/status-transition-engine/spec.md */
    public static function success(array $data=[]): self
    {
        return new self(ok: true, error: null, data: $data);
    }//end success()

    /**
     * Convenience constructor for a failed result.
     *
     * @param string               $error Static error message
     * @param array<string, mixed> $data  Optional structured data
     *
     * @return self
     */
    /** @spec openspec/specs/status-transition-engine/spec.md */
    public static function failure(string $error, array $data=[]): self
    {
        return new self(ok: false, error: $error, data: $data);
    }//end failure()
}//end class
