<?php

/**
 * Procest SideEffectDispatcher
 *
 * Dispatches the `automaticActions[]` array attached to a status transition
 * after the status mutation has been persisted. Recognises both inline
 * action JSON (legacy) and `{ref: <slug>}` references that resolve via
 * ActionRegistry against the per-tenant action library.
 *
 * Failure semantics (inherited from status-transition-engine REQ-STE-9):
 *  - Failed actions are recorded on `statusRecord.dispatchedActions[]` with
 *    a static error code.
 *  - Status mutations are NEVER rolled back due to a side-effect failure.
 *  - Unknown action types AND unknown refs both surface as
 *    `{ok: false, error: 'unknown_action_ref'}` (cross-tenant misses are
 *    indistinguishable from non-existent slugs in user-visible output —
 *    REQ-AA-8).
 *
 * @category Service
 * @package  OCA\Procest\Service\Transitions
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Transitions;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Actions\ActionRegistry;
use OCA\Procest\Service\Actions\ActionResult;
use Psr\Log\LoggerInterface;

/**
 * Iterates `automaticActions[]` in declaration order and invokes the handler
 * registered for each resolved action's `type`.
 */
class SideEffectDispatcher
{
    /**
     * Constructor for SideEffectDispatcher.
     *
     * @param ActionRegistry  $registry The per-tenant action registry.
     * @param LoggerInterface $logger   PSR-3 logger for failures and unknown
     *                                  references.
     *
     * @return void
     */
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch every action on a transition.
     *
     * @param array $actions           The raw `automaticActions[]` array from
     *                                 the workflow template's transition. May
     *                                 contain inline action configs OR
     *                                 `{ref: <slug>}` references.
     * @param array $case              The full case object (used by handlers
     *                                 for template rendering and routing).
     * @param array $transitionContext Engine context: `fromStatus`,
     *                                 `toStatus`, `transitionLabel`,
     *                                 `userId`, `statusRecordUuid`,
     *                                 `tenantId`, and optional `dryRun`.
     *
     * @return array<int, array> One audit entry per action, suitable for
     *                           persisting on `statusRecord.dispatchedActions`.
     */
    public function dispatch(array $actions, array $case, array $transitionContext): array
    {
        $tenantId = (string) ($transitionContext['tenantId'] ?? ($case['tenantId'] ?? ''));
        $results  = [];

        foreach ($actions as $entry) {
            if (is_array($entry) === false) {
                $results[] = [
                    'type'  => 'unknown',
                    'ok'    => false,
                    'error' => 'unknown_action_ref',
                ];
                continue;
            }

            $resolved = $this->resolveEntry($entry, $tenantId);
            if ($resolved === null) {
                $results[] = [
                    'type'  => 'unknown',
                    'ref'   => (string) ($entry['ref'] ?? ''),
                    'ok'    => false,
                    'error' => 'unknown_action_ref',
                ];
                continue;
            }

            [$type, $config, $ref] = $resolved;

            $handler = $this->registry->getHandler($type);
            if ($handler === null) {
                $this->logger->error(
                    'SideEffectDispatcher: unknown action type',
                    [
                        'app'      => Application::APP_ID,
                        'type'     => $type,
                        'ref'      => $ref,
                        'tenantId' => $tenantId,
                    ]
                );
                $audit = [
                    'type'  => $type,
                    'ok'    => false,
                    'error' => 'unknown_action_type',
                ];
                if ($ref !== null) {
                    $audit['ref'] = $ref;
                }
                $results[] = $audit;
                continue;
            }

            try {
                $result = $handler->handle($config, $case, $transitionContext);
            } catch (\Throwable $e) {
                // Defensive — handlers MUST swallow exceptions, but a buggy
                // third-party handler must not abort the rest of the loop.
                $this->logger->error(
                    'SideEffectDispatcher: handler threw',
                    [
                        'app'       => Application::APP_ID,
                        'type'      => $type,
                        'ref'       => $ref,
                        'tenantId'  => $tenantId,
                        'exception' => $e->getMessage(),
                    ]
                );
                $audit = [
                    'type'  => $type,
                    'ok'    => false,
                    'error' => 'handler_exception',
                ];
                if ($ref !== null) {
                    $audit['ref'] = $ref;
                }
                $results[] = $audit;
                continue;
            }

            $audit = $this->resultToAudit($type, $ref, $result);
            $results[] = $audit;
        }

        return $results;
    }//end dispatch()

    /**
     * Resolve a single `automaticActions[]` entry to `[type, config, ref|null]`.
     *
     * - `{ref: <slug>}` entries go through ActionRegistry; cross-tenant misses
     *   and unpublished slugs both return null.
     * - Inline action JSON entries (with a `type` key, no `ref`) are returned
     *   as-is for backward compatibility (REQ-AA-5).
     *
     * @param array  $entry    Raw `automaticActions[]` element.
     * @param string $tenantId Tenant the current transition is firing in.
     *
     * @return array{0:string,1:array,2:?string}|null
     */
    private function resolveEntry(array $entry, string $tenantId): ?array
    {
        if (isset($entry['ref']) === true) {
            $slug = (string) $entry['ref'];
            if ($slug === '' || $tenantId === '') {
                return null;
            }
            $action = $this->registry->resolve($tenantId, $slug);
            if ($action === null) {
                return null;
            }
            $type   = (string) ($action['type'] ?? '');
            $config = (array) ($action['config'] ?? []);
            if ($type === '') {
                return null;
            }
            return [$type, $config, $slug];
        }

        // Inline (legacy) — must include a `type` key directly.
        $type = (string) ($entry['type'] ?? '');
        if ($type === '') {
            return null;
        }
        $config = $entry;
        unset($config['type']);
        return [$type, $config, null];
    }//end resolveEntry()

    /**
     * Convert an ActionResult plus envelope metadata to an audit entry.
     *
     * @param string       $type   Handler type slug.
     * @param string|null  $ref    Originating action slug (when resolved via
     *                             registry) or null for inline actions.
     * @param ActionResult $result The handler's return value.
     *
     * @return array
     */
    private function resultToAudit(string $type, ?string $ref, ActionResult $result): array
    {
        $audit = [
            'type' => $type,
            'ok'   => $result->ok,
        ];
        if ($ref !== null) {
            $audit['ref'] = $ref;
        }
        if ($result->ok === false && $result->error !== null) {
            $audit['error'] = $result->error;
        }
        if ($result->data !== []) {
            $audit['data'] = $result->data;
        }
        return $audit;
    }//end resultToAudit()
}//end class
