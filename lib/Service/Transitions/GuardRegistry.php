<?php

/**
 * Procest Guard Registry.
 *
 * Strategy-pattern registry mapping guard `type` strings to the corresponding
 * GuardEvaluatorInterface implementations. Built-in evaluators are injected
 * via DI; downstream specs MAY register additional types via
 * `registerEvaluator()` (e.g. an integration hook in `Application::register()`).
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

use Psr\Log\LoggerInterface;

/**
 * Registry of guard evaluators keyed by guard type.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T06
 */
class GuardRegistry
{

    /**
     * Registered evaluators keyed by guard type.
     *
     * @var array<string, GuardEvaluatorInterface>
     */
    private array $evaluators = [];

    /**
     * Constructor.
     *
     * @param ChecklistGuard        $checklist        Built-in checklist evaluator
     * @param RequiredFieldGuard    $requiredField    Built-in required-field evaluator
     * @param RequiredDocumentGuard $requiredDocument Built-in required-document evaluator
     * @param RoleGuard             $roleGuard        Built-in role evaluator
     * @param LoggerInterface       $logger           Logger for unknown guard types
     */
    public function __construct(
        ChecklistGuard $checklist,
        RequiredFieldGuard $requiredField,
        RequiredDocumentGuard $requiredDocument,
        RoleGuard $roleGuard,
        private readonly LoggerInterface $logger,
    ) {
        $this->evaluators = [
            'checklist'        => $checklist,
            'requiredField'    => $requiredField,
            'requiredDocument' => $requiredDocument,
            'roleGuard'        => $roleGuard,
        ];
    }//end __construct()

    /**
     * Register an additional evaluator (DI extension point).
     *
     * @param string                   $type      Guard type identifier
     * @param GuardEvaluatorInterface  $evaluator Evaluator implementation
     *
     * @return void
     */
    public function registerEvaluator(string $type, GuardEvaluatorInterface $evaluator): void
    {
        $this->evaluators[$type] = $evaluator;
    }//end registerEvaluator()

    /**
     * Evaluate every guard in declaration order and collect snapshots.
     *
     * @param array<int, array<string, mixed>> $guards List of guard configs
     * @param array<string, mixed>             $case   The case
     * @param string                           $userId Current user UID
     *
     * @return array<int, array{type: string, passed: bool, failureMessage: ?string, details: array<string, mixed>}>
     */
    public function evaluateAll(array $guards, array $case, string $userId): array
    {
        $results = [];
        foreach ($guards as $guard) {
            if (is_array($guard) === false) {
                continue;
            }
            $type = (string) ($guard['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (isset($this->evaluators[$type]) === false) {
                $this->logger->warning('Unknown guard type', ['type' => $type]);
                $results[] = [
                    'type'           => $type,
                    'passed'         => false,
                    'failureMessage' => 'Onbekende guard',
                    'details'        => ['unknown' => true],
                ];
                continue;
            }

            $result    = $this->evaluators[$type]->evaluate(guardConfig: $guard, case: $case, userId: $userId);
            $results[] = [
                'type'           => $type,
                'passed'         => $result->passed,
                'failureMessage' => $result->failureMessage,
                'details'        => $result->details,
            ];
        }

        return $results;
    }//end evaluateAll()

    /**
     * Check if all guards in the result set passed.
     *
     * @param array<int, array{passed: bool}> $results The evaluateAll() output
     *
     * @return bool
     */
    public function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['passed'] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }//end allPassed()
}//end class
