<?php

/**
 * Procest Role Resolver Service
 *
 * Central engine that resolves a `routingRule` plus a `case` to an ordered
 * set of participant references. Owns:
 *   - Legacy normalisation: `assigneeRole` -> single-role,
 *     `allowedRoles` -> or-set.
 *   - Strategy dispatch via StrategyRegistry.
 *   - Delegation substitution + cycle detection on `role.delegate`.
 *   - APCu cache layer keyed by `(ruleHash, caseId)` for 60s.
 *
 * Callers: task list builder, status-transition engine, ParafeerRouteService,
 * /api/cases/{id}/reroute controller.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Routing\RoutingStrategyMissingException;
use OCA\Procest\Service\Routing\StrategyRegistry;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Central role-routing engine.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T02
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates strategies,
 *   OpenRegister, cache and logger.
 */
class RoleResolverService
{
    /**
     * Default strategy name when normalising legacy fields.
     */
    public const STRATEGY_SINGLE_ROLE = 'single-role';

    /**
     * Strategy name used to normalise `allowedRoles`.
     */
    public const STRATEGY_OR_SET = 'or-set';

    /**
     * APCu cache TTL (seconds) for resolver results.
     */
    private const CACHE_TTL = 60;

    /**
     * The local cache instance (APCu when available).
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Constructor.
     *
     * @param StrategyRegistry $registry        Strategy registry
     * @param SettingsService  $settingsService Bridge to ObjectService + config
     * @param ICacheFactory    $cacheFactory    Cache factory
     * @param LoggerInterface  $logger          Logger
     */
    public function __construct(
        private readonly StrategyRegistry $registry,
        private readonly SettingsService $settingsService,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createLocal(Application::APP_ID.'_routing');
    }//end __construct()

    /**
     * Normalise a step or transition into a concrete routing rule.
     *
     * Order of precedence:
     *   1. Explicit `routingRule` object on the step/transition.
     *   2. Legacy `assigneeRole` (UUID) -> single-role.
     *   3. Legacy `allowedRoles` (UUID array) -> or-set.
     *
     * Returns null when nothing routable is declared (caller decides default).
     *
     * @param array<string, mixed> $entry The step or transition payload
     *
     * @return array<string, mixed>|null
     */
    public function normaliseRule(array $entry): ?array
    {
        $rule = $entry['routingRule'] ?? null;
        if (is_array($rule) === true && isset($rule['strategy']) === true) {
            return $rule;
        }

        $assigneeRole = (string) ($entry['assigneeRole'] ?? '');
        if ($assigneeRole !== '') {
            return [
                'strategy' => self::STRATEGY_SINGLE_ROLE,
                'roleType' => $assigneeRole,
            ];
        }

        $allowedRoles = $entry['allowedRoles'] ?? null;
        if (is_array($allowedRoles) === true && $allowedRoles !== []) {
            return [
                'strategy'  => self::STRATEGY_OR_SET,
                'roleTypes' => array_values(
                        array_map(
                    static fn ($value): string => (string) $value,
                    $allowedRoles,
                )
                        ),
            ];
        }

        return null;
    }//end normaliseRule()

    /**
     * Resolve a routing rule against a case.
     *
     * @param array<string, mixed> $rule The (already normalised) routing rule
     * @param array<string, mixed> $case The case object (must include id, caseType)
     *
     * @return array<int, string> Ordered participant refs (post-delegation)
     *
     * @throws RoutingStrategyMissingException When the rule's strategy is unknown
     */
    public function resolve(array $rule, array $case): array
    {
        $strategyName = (string) ($rule['strategy'] ?? '');
        if ($this->registry->has($strategyName) === false) {
            throw new RoutingStrategyMissingException(
                message: sprintf('Routing strategy "%s" is not registered', $strategyName)
            );
        }

        $caseId   = (string) ($case['id'] ?? ($case['uuid'] ?? ''));
        $cacheKey = $this->cacheKey(rule: $rule, caseId: $caseId);
        if ($caseId !== '') {
            $cacheHit = $this->cache->get($cacheKey);
        } else {
            $cacheHit = null;
        }

        if (is_array($cacheHit) === true) {
            return array_values(
                    array_map(
                static fn ($value): string => (string) $value,
                $cacheHit,
            )
                    );
        }

        $roles    = $this->loadCaseRoles(caseId: $caseId);
        $strategy = $this->registry->get($strategyName);
        $primary  = $strategy->resolve($rule, $case, $roles);

        $fallback = (string) ($rule['fallback'] ?? '');
        if ($primary === [] && $fallback !== '' && $strategyName !== 'hierarchical') {
            $primary = $this->registry
                ->get(self::STRATEGY_SINGLE_ROLE)
                ->resolve(['strategy' => self::STRATEGY_SINGLE_ROLE, 'roleType' => $fallback], $case, $roles);
        }

        $resolved = $this->applyDelegation(participants: $primary, roles: $roles);

        if ($caseId !== '') {
            $this->cache->set($cacheKey, $resolved, self::CACHE_TTL);
        }

        if ($resolved === []) {
            $this->logger->info(
                'Procest: routing rule resolved to empty set',
                [
                    'event'  => 'RoleRoutingEmpty',
                    'rule'   => $rule,
                    'caseId' => $caseId,
                    'app'    => Application::APP_ID,
                ],
            );
        }

        return $resolved;
    }//end resolve()

    /**
     * Whether the given user is permitted to execute against the rule.
     *
     * Convenience for status-transition guard evaluation.
     *
     * @param array<string, mixed> $rule   The routing rule
     * @param array<string, mixed> $case   The case
     * @param string               $userId The candidate user id
     *
     * @return bool
     */
    public function canExecute(array $rule, array $case, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        try {
            $allowed = $this->resolve(rule: $rule, case: $case);
        } catch (RoutingStrategyMissingException $e) {
            $this->logger->warning(
                'Procest: routing guard rejected — missing strategy: '.$e->getMessage(),
            );
            return false;
        }

        return in_array($userId, $allowed, true);
    }//end canExecute()

    /**
     * Invalidate the cache for every rule against a case.
     *
     * Called by the role-mutation listener.
     *
     * @param string $caseId The case UUID/id
     *
     * @return void
     */
    public function invalidateCache(string $caseId): void
    {
        if ($caseId === '') {
            return;
        }

        // We cannot enumerate keys on ICache, so clear the whole local segment.
        // Acceptable: the cache is per-app, the segment is namespaced and
        // resolver hits are rebuilt within 60s anyway.
        $this->cache->clear();
    }//end invalidateCache()

    /**
     * Substitute delegates inside an active delegation window; break cycles.
     *
     * @param array<int, string>               $participants Raw resolver output
     * @param array<int, array<string, mixed>> $roles        All case roles
     *
     * @return array<int, string>
     */
    private function applyDelegation(array $participants, array $roles): array
    {
        $now    = new DateTimeImmutable('now');
        $byUser = [];
        foreach ($roles as $role) {
            $participant = (string) ($role['participant'] ?? '');
            if ($participant !== '') {
                $byUser[$participant] = $role;
            }
        }

        $result = [];
        foreach ($participants as $participant) {
            $resolved = $participant;
            $visited  = [$participant => true];
            $hops     = 0;
            while (isset($byUser[$resolved]) === true) {
                $role     = $byUser[$resolved];
                $from     = (string) ($role['delegateFrom'] ?? '');
                $until    = (string) ($role['delegateUntil'] ?? '');
                $delegate = (string) ($role['delegate'] ?? '');
                if ($delegate === '' || $from === '' || $until === '') {
                    break;
                }

                try {
                    $fromAt  = new DateTimeImmutable($from);
                    $untilAt = new DateTimeImmutable($until);
                } catch (Throwable $e) {
                    break;
                }

                if ($now < $fromAt || $now > $untilAt) {
                    break;
                }

                if (isset($visited[$delegate]) === true) {
                    $this->logger->warning(
                        'Procest: delegation cycle detected',
                        [
                            'event'    => 'RoleRoutingDelegationCycle',
                            'original' => $participant,
                            'delegate' => $delegate,
                            'app'      => Application::APP_ID,
                        ],
                    );
                    break;
                }

                $visited[$delegate] = true;
                $resolved           = $delegate;
                $hops++;
                if ($hops >= 1) {
                    // Per spec: break after exactly one hop.
                    break;
                }
            }//end while

            $result[] = $resolved;
        }//end foreach

        return $result;
    }//end applyDelegation()

    /**
     * Build a cache key from rule + caseId.
     *
     * @param array<string, mixed> $rule   The rule
     * @param string               $caseId The case id
     *
     * @return string
     */
    private function cacheKey(array $rule, string $caseId): string
    {
        $hash = md5(serialize($rule));
        return sprintf('rrs.%s.%s', $hash, $caseId);
    }//end cacheKey()

    /**
     * Load the role records bound to a case via ObjectService.
     *
     * @param string $caseId The case id
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCaseRoles(string $caseId): array
    {
        if ($caseId === '') {
            return [];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('role_schema');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $records = $objectService->findAll(
                $register,
                $schema,
                ['filters' => ['case' => $caseId]],
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: failed to load roles for case '.$caseId.': '.$e->getMessage(),
            );
            return [];
        }

        $rows = [];
        foreach ((array) $records as $record) {
            $row = $this->toArray(value: $record);
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }//end loadCaseRoles()

    /**
     * Coerce ObjectService return to plain array.
     *
     * @param mixed $value The record (entity or array)
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialised = $value->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $value;
        }

        return [];
    }//end toArray()

    /**
     * Throw a runtime exception with a static message.
     *
     * Helper for callers that wrap resolution; kept for symmetry.
     *
     * @param string $message Failure label
     *
     * @return never
     *
     * @throws RuntimeException
     */
    public function fail(string $message): never
    {
        throw new RuntimeException($message);
    }//end fail()
}//end class
