<?php

/**
 * How dossiq asks OpenRegister's task inbox a question.
 *
 * 🔴 SPLIT OUT OF EngineTaskInbox BECAUSE THAT CLASS REACHED PHPMD'S
 * ExcessiveClassComplexity THRESHOLD OF EXACTLY 50 — the same wall
 * EngineTaskInbox itself was split off EngineTaskGateway to get away from.
 * A due-window count took it to 51 and reddened `development`, which is the
 * measurement saying the class had grown a second job.
 *
 * The seam is a real one. EngineTaskInbox knows what dossiq CALLS things:
 * `status`, `dueDate`, `case`, a checklist that must stay a typed list. This
 * class knows how to ASK: resolving an optional service by name, building
 * another app's criteria object with named arguments, unwrapping whatever
 * envelope came back, and telling a failed read apart from an empty one.
 * Neither half needs the other's vocabulary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

use DateTime;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds, makes and unwraps one engine inbox read.
 *
 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
 */
class EngineInboxQuery {

    /**
     * OpenRegister's inbox criteria, reached by name.
     *
     * A constant rather than a literal per call site, because a rename
     * missing one of them makes that read silently answer empty — the exact
     * shape the fleet's namespace rename cost two weeks.
     *
     * @var string
     */
    private const CRITERIA = 'OCA\OpenRegister\Db\TaskInboxCriteria';

    /**
     * Why the last read answered nothing, or '' when it did not fail.
     *
     * 🔴 A READ THAT FAILS AND A CASE WITH NO TASKS BOTH ANSWER `[]`, and
     * for one caller that difference decides a transition. The checklist
     * guard must fail CLOSED when the engine cannot be read: a guard that
     * passes because the store was unavailable stops guarding at exactly
     * the moment it matters. Callers that genuinely do not care (the work
     * queue, the backfill's dedup read) simply never ask.
     *
     * @var string
     */
    private string $lastError = '';

    /**
     * Constructor.
     *
     * @param SettingsService    $settings  Bridge to OpenRegister plus app config.
     * @param ContainerInterface $container The app container, for the optional inbox service.
     * @param LoggerInterface    $logger    Records a read that could not be made.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Why the last read on this instance answered nothing.
     *
     * @return string The engine's message, or '' when the read succeeded.
     *
     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function lastError(): string {
        return $this->lastError;
    }//end lastError()

    /**
     * The rows one read returns, still in the engine's own shape.
     *
     * @param array<string, mixed> $criteria Named arguments for the criteria.
     * @param integer              $limit    How many rows at most.
     * @param array{0: string, 1: array<string, mixed>} $failure Log message and context.
     *
     * @return array<int, mixed> The rows.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    public function rows(array $criteria, int $limit, array $failure): array {
        return $this->rowsOf(value: $this->envelope(criteria: $criteria, limit: $limit, failure: $failure));
    }//end rows()

    /**
     * How many tasks match, counted by the ENGINE rather than by dossiq.
     *
     * 🔑 THE COUNT IS NOT `count($rows)`. The inbox pages, so counting the
     * rows it returned answers the page size once there are more matches
     * than the limit: a dashboard tile would read "200 open tasks" for
     * ever. The envelope carries `total`, which is a second query over the
     * same predicates, so a page of one is asked for and its rows thrown
     * away.
     *
     * @param array<string, mixed> $criteria Named arguments for the criteria.
     * @param array{0: string, 1: array<string, mixed>} $failure Log message and context.
     *
     * @return integer The total, or 0 when the read could not be made.
     *
     * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
     */
    public function total(array $criteria, array $failure): int {
        $page = $this->envelope(criteria: $criteria, limit: 1, failure: $failure);
        if (is_array($page) === false) {
            return 0;
        }

        return (int) ($page['total'] ?? 0);
    }//end total()

    /**
     * The due-window criteria arguments, for the bounds that parse.
     *
     * A bound that does not parse is DROPPED rather than passed on as now:
     * a window silently reset to the current instant answers a plausible
     * number for the wrong question, which is worse than answering the
     * unbounded one.
     *
     * @param string|null $after  The lower bound.
     * @param string|null $before The upper bound.
     *
     * @return array<string, DateTime> The arguments to add.
     *
     * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
     */
    public function dueWindow(?string $after, ?string $before): array {
        $window = [];
        foreach (['dueAfter' => $after, 'dueBefore' => $before] as $name => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            try {
                $window[$name] = new DateTime($value);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Dossiq: a due-window bound the engine cannot read was dropped',
                    ['bound' => $name, 'value' => $value]
                );
            }
        }

        return $window;
    }//end dueWindow()

    /**
     * The name of one of the criteria's scope constants.
     *
     * The constants belong to another app's class, so a caller that wants
     * `SCOPE_ALL` would otherwise have to name that class itself and the
     * constant would be declared in two places.
     *
     * @param string $name The constant, e.g. `SCOPE_ALL`.
     *
     * @return string Its value, or '' when the class is absent.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    public function scope(string $name): string {
        $criteriaClass = self::CRITERIA;
        if (defined($criteriaClass . '::' . $name) === false) {
            return '';
        }

        return (string) constant($criteriaClass . '::' . $name);
    }//end scope()

    /**
     * One inbox read, as the engine's own envelope.
     *
     * The single place the criteria is constructed and the read is made, so
     * a caller cannot forget the catch. A read that fails LOGS and answers
     * nothing; `lastError()` is how a caller that must fail closed tells
     * that apart from a genuinely empty answer.
     *
     * @param array<string, mixed> $criteria Named arguments for the criteria.
     * @param integer              $limit    How many rows at most.
     * @param array{0: string, 1: array<string, mixed>} $failure Log message and context.
     *
     * @return mixed The engine's response, or null.
     *
     * @psalm-suppress UndefinedClass `OCA\OpenRegister\Db\TaskInboxCriteria`
     *   is another APP's class, reached by name. It exists, in
     *   openregister/lib/Db/TaskInboxCriteria.php, but dossiq's psalm run has
     *   openregister nowhere on its include path and never will -- OpenRegister
     *   is a separate app that need not be installed at all. `class_exists()`
     *   and `container->get()` further down take the same kind of name as a
     *   plain string and psalm never resolves them; `new $criteriaClass(...)`
     *   is different, because psalm folds the literal back into a class
     *   reference and demands it at ANALYSIS time. That is the tool being
     *   wrong about a deliberately runtime-resolved binding, not a finding.
     *   The catch below is what actually handles the class being absent.
     *
     * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
     */
    private function envelope(array $criteria, int $limit, array $failure): mixed {
        // NOTE: no `class_exists` guard on the criteria, deliberately. The
        // catch below already handles an absent class -- a missing class
        // throws `Error`, which is a `Throwable` -- and it LOGS, where a
        // guard would return silently.
        $this->lastError = '';

        $inbox = $this->resolveInbox();
        if ($inbox === null) {
            $this->lastError = 'the task engine is not available';

            return null;
        }

        $criteriaClass = self::CRITERIA;

        try {
            return $inbox->inbox(new $criteriaClass(...$criteria), $limit, 0);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();

            // 🔴 A VERSION MISMATCH IS NOT A QUIET DAY. Every filter here is
            // a NAMED argument on another app's class, so an OpenRegister
            // older than the one that added a filter throws "Unknown named
            // parameter", this catch turns it into no rows, and a count
            // built on it answers a plausible ZERO for ever. Measured: the
            // dashboard's "due today" tile read 0 against an instance whose
            // OpenRegister predated the due-window filter, and the only
            // trace was one warning among sixty.
            //
            // It is logged at ERROR and names the parameter, because the
            // fix is an upgrade and nobody goes looking for one on the
            // strength of a number that looks like "no work today".
            if (str_contains($e->getMessage(), 'Unknown named parameter') === true) {
                $this->logger->error(
                    'Dossiq: the installed OpenRegister does not accept a task filter this app sends. '
                    . 'Counts and lists using it will read empty until OpenRegister is upgraded.',
                    [
                        'exception' => $e->getMessage(),
                        'criteria' => array_keys($criteria),
                    ]
                );

                return null;
            }

            $this->logger->warning($failure[0], (['exception' => $e->getMessage()] + $failure[1]));

            return null;
        }
    }//end envelope()

    /**
     * Pull the rows out of whichever envelope the inbox returned.
     *
     * @param mixed $value The inbox response.
     *
     * @return array<int, mixed> The rows.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    private function rowsOf(mixed $value): array {
        if (is_array($value) === false) {
            return [];
        }

        foreach (['results', 'tasks', 'items'] as $envelope) {
            if (isset($value[$envelope]) === true && is_array($value[$envelope]) === true) {
                return array_values($value[$envelope]);
            }
        }

        return array_values($value);
    }//end rowsOf()

    /**
     * Resolve OpenRegister's task inbox service, or null.
     *
     * PROTECTED, not private, and for the same reason as
     * `EngineTaskGateway::resolveService()`: without a seam there is no way
     * to reach the reading below in a unit test. OpenRegister is not
     * installed in dossiq's test run, so `class_exists()` is false and every
     * read short-circuits before it does any work — a test would assert on
     * an empty array and pass whatever the body did.
     *
     * @return object|null The service, or null when unavailable.
     *
     * @psalm-suppress MixedReturnStatement
     * @psalm-suppress MixedInferredReturnType
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    protected function resolveInbox(): ?object {
        $className = 'OCA\OpenRegister\Service\Task\TaskInboxService';
        if ($this->settings->isOpenRegisterAvailable() === false || class_exists($className) === false) {
            return null;
        }

        try {
            return $this->container->get($className);
        } catch (Throwable $e) {
            return null;
        }
    }//end resolveInbox()
}//end class
