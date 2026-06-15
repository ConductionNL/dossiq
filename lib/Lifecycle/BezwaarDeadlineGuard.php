<?php

/**
 * Procest bezwaar deadline guard.
 *
 * OpenRegister lifecycle guard (consumed via x-openregister-lifecycle
 * `requires`) gating the `beslissen` transition on the `bezwaar` schema. The
 * statutory beslistermijn (AWB art. 7:10) is captured declaratively in the
 * `decisionDeadline` field (computed by x-openregister-calculations). This
 * guard denies a beslissing op bezwaar once that deadline has passed without
 * a recorded verdaging/opschorting, so the decision cannot silently miss the
 * term. Read-only: no mutations, no side effects (ADR-022, ADR-031).
 *
 * @category Lifecycle
 * @package  OCA\Procest\Lifecycle
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

namespace OCA\Procest\Lifecycle;

use DateTimeImmutable;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Allows the bezwaar `beslissen` transition only while the statutory
 * decision deadline (AWB art. 7:10) has not been exceeded.
 *
 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-3.3
 */
class BezwaarDeadlineGuard implements LifecycleGuardInterface
{
    /**
     * Authorise (or deny) the transition.
     *
     * @param array<string, mixed> $object The loaded bezwaar payload at its current state.
     * @param string               $action The transition action being applied.
     * @param string               $userId The uid of the caller.
     *
     * @return GuardResult Allow when the deadline is unset or not yet passed, deny otherwise.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action/$userId are mandated by the interface; this guard reads only the payload.
     *
     * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-3.3
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        $deadlineRaw = trim((string) ($object['decisionDeadline'] ?? ''));

        // No deadline recorded yet — nothing to enforce.
        if ($deadlineRaw === '') {
            return GuardResult::allow();
        }

        try {
            $deadline = new DateTimeImmutable($deadlineRaw);
        } catch (\Exception $e) {
            // An unparseable deadline cannot be used to block a statutory
            // decision; fail open so the decision is never silently lost.
            return GuardResult::allow();
        }

        if ($this->now() > $deadline) {
            return GuardResult::deny(
                'De beslistermijn van het bezwaar is verstreken (AWB art. 7:10). '
                .'Leg eerst een verdaging of opschorting vast voordat de beslissing wordt genomen.'
            );
        }

        return GuardResult::allow();
    }//end check()

    /**
     * Current moment, normalised to the start of the day so a decision taken
     * on the deadline date itself is still allowed. Overridable in tests.
     *
     * @return DateTimeImmutable Today at 00:00.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }//end now()
}//end class
