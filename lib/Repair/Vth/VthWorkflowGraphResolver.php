<?php

/**
 * Procest VTH workflow graph resolver.
 *
 * Turns the `steps[]` and `transitions[]` blocks of a VTH workflow-template
 * catalog entry into the resolved graph the workflowTemplate schema expects,
 * binding every status NAME to the statusType UUID it refers to.
 *
 * Split out of {@see \OCA\Procest\Repair\SeedVthWorkflowTemplates}: the seed
 * step's job is orchestration (read catalog, resolve context, create + publish),
 * while translating one catalog entry's graph is a self-contained, purely
 * computational concern with its own all-or-nothing rule — an unresolvable
 * status name fails the WHOLE template rather than seeding a partial graph.
 *
 * @category Repair
 * @package  OCA\Procest\Repair\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair\Vth;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Resolves a VTH catalog entry's steps/transitions against a statusType map.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthWorkflowGraphResolver
{
    /**
     * UUID5 namespace for deterministic step/transition ids derived from
     * template slug + child slug.
     */
    private const NS_UUID = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Constructor for VthWorkflowGraphResolver.
     *
     * @param LoggerInterface $logger Logger.
     *
     * @return void
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Resolve the steps[] and transitions[] blocks against the status map.
     *
     * Returns null when any status name does not resolve — the caller reports
     * that as skipped (no partial seed).
     *
     * @param array<string, mixed>  $data      The decoded catalog entry.
     * @param string                $slug      The template slug.
     * @param array<string, string> $statusMap Status name → UUID map.
     *
     * @return array<string, mixed>|null {steps, transitions}, or null when unresolved
     *
     * @spec openspec/specs/vth-workflow-templates/spec.md
     */
    public function resolve(array $data, string $slug, array $statusMap): ?array
    {
        $resolvedSteps = $this->resolveSteps(
            slug: $slug,
            rawSteps: ($data['steps'] ?? []),
            statusMap: $statusMap,
        );
        if ($resolvedSteps === null) {
            $this->logger->warning(
                'Procest: VTH workflow template — unresolved status in steps, skipping',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return null;
        }

        $resolvedTransitions = $this->resolveTransitions(
            slug: $slug,
            rawTransitions: ($data['transitions'] ?? []),
            statusMap: $statusMap,
        );
        if ($resolvedTransitions === null) {
            $this->logger->warning(
                'Procest: VTH workflow template — unresolved status in transitions, skipping',
                ['app' => Application::APP_ID, 'slug' => $slug]
            );
            return null;
        }

        return [
            'steps'       => $resolvedSteps,
            'transitions' => $resolvedTransitions,
        ];
    }//end resolve()

    /**
     * Resolve the steps[] block against the status name → UUID map.
     * Returns null when any status name does not resolve.
     *
     * `$rawSteps` is deliberately typed as a list of MIXED: it comes straight
     * from `json_decode()` of a catalog file, so a malformed entry can be a
     * scalar or null. The `is_array()` guard below is the check that drops it.
     *
     * @param string                $slug      The template slug (for UUID5 ids)
     * @param array<int, mixed>     $rawSteps  Steps from the catalog file
     * @param array<string, string> $statusMap Name → UUID map
     *
     * @return array<int, array<string, mixed>>|null Resolved steps, or null
     */
    private function resolveSteps(string $slug, array $rawSteps, array $statusMap): ?array
    {
        $resolved = [];
        foreach ($rawSteps as $step) {
            if (is_array($step) === false) {
                continue;
            }

            $statusName = (string) ($step['statusName'] ?? '');
            if ($statusName === '' || isset($statusMap[$statusName]) === false) {
                return null;
            }

            $stepSlug   = (string) ($step['slug'] ?? '');
            $resolved[] = [
                'id'           => $this->deterministicId(template: $slug, child: 'step-'.$stepSlug),
                'slug'         => $stepSlug,
                'title'        => (string) ($step['title'] ?? ''),
                'status'       => $statusMap[$statusName],
                'statusName'   => $statusName,
                'order'        => (int) ($step['order'] ?? 0),
                'isInitial'    => (bool) ($step['isInitial'] ?? false),
                'isFinal'      => (bool) ($step['isFinal'] ?? false),
                'assigneeRole' => ($step['assigneeRole'] ?? null),
                'description'  => (string) ($step['description'] ?? ''),
            ];
        }//end foreach

        return $resolved;
    }//end resolveSteps()

    /**
     * Resolve the transitions[] block against the status name → UUID map.
     * Accepts "*" as a wildcard for fromStatus (any status). Returns null
     * when any non-wildcard status name does not resolve.
     *
     * `$rawTransitions` is deliberately typed as a list of MIXED: it comes
     * straight from `json_decode()` of a catalog file, so a malformed entry can
     * be a scalar or null. The `is_array()` guard below is the check that
     * drops it.
     *
     * @param string                $slug           The template slug (for UUID5 ids)
     * @param array<int, mixed>     $rawTransitions Transitions from the catalog file
     * @param array<string, string> $statusMap      Name → UUID map
     *
     * @return array<int, array<string, mixed>>|null Resolved transitions, or null
     */
    private function resolveTransitions(string $slug, array $rawTransitions, array $statusMap): ?array
    {
        $resolved = [];
        foreach ($rawTransitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            $toName = (string) ($transition['toStatus'] ?? '');
            if ($toName === '' || isset($statusMap[$toName]) === false) {
                return null;
            }

            $fromName = (string) ($transition['fromStatus'] ?? '');
            $fromId   = $this->resolveFromStatus(fromName: $fromName, statusMap: $statusMap);
            if ($fromId === null) {
                return null;
            }

            $transitionSlug = (string) ($transition['slug'] ?? '');
            $resolved[]     = [
                'id'               => $this->deterministicId(template: $slug, child: 'transition-'.$transitionSlug),
                'slug'             => $transitionSlug,
                'label'            => (string) ($transition['label'] ?? ''),
                'fromStatus'       => $fromId,
                'fromStatusName'   => $fromName,
                'toStatus'         => $statusMap[$toName],
                'toStatusName'     => $toName,
                'allowedRoles'     => ($transition['allowedRoles'] ?? []),
                'guards'           => ($transition['guards'] ?? []),
                'automaticActions' => ($transition['automaticActions'] ?? []),
                'deadline'         => ($transition['deadline'] ?? null),
            ];
        }//end foreach

        return $resolved;
    }//end resolveTransitions()

    /**
     * Resolve one transition's fromStatus name to a UUID.
     *
     * The literal `*` is a wildcard meaning "from any status" and is passed
     * through unchanged. Returns null when a concrete name does not resolve.
     *
     * @param string                $fromName  The catalog fromStatus name or `*`.
     * @param array<string, string> $statusMap Name → UUID map.
     *
     * @return string|null The status UUID, `*`, or null when unresolved.
     */
    private function resolveFromStatus(string $fromName, array $statusMap): ?string
    {
        if ($fromName === '*') {
            return '*';
        }

        if ($fromName === '' || isset($statusMap[$fromName]) === false) {
            return null;
        }

        return $statusMap[$fromName];
    }//end resolveFromStatus()

    /**
     * Generate a deterministic UUID5 from a template slug + child slug.
     * Re-running the repair step therefore produces stable step / transition
     * ids per template.
     *
     * @param string $template The template slug
     * @param string $child    The child slug (e.g. "step-ontvangen")
     *
     * @return string The deterministic UUID5
     */
    private function deterministicId(string $template, string $child): string
    {
        $namespace = str_replace('-', '', self::NS_UUID);
        $nameBytes = hex2bin($namespace).$template.':'.$child;
        $hash      = sha1($nameBytes);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }//end deterministicId()
}//end class
