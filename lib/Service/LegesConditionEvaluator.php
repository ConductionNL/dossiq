<?php

/**
 * Procest Leges Condition Evaluator
 *
 * Pure rules engine that evaluates JSON condition objects (used by leges
 * variants and discounts) against case attributes and supplementary lookup
 * data (BRP date-of-birth, minima registration, previous applications).
 *
 * The evaluator is deliberately side-effect free: callers resolve the
 * supplementary data (BRP, minima, history) and pass it in, so the matching
 * logic stays deterministic and fully unit-testable.
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
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-003
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-004
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Evaluates leges selection/discount conditions against case + context data.
 *
 * @psalm-suppress UnusedClass
 */
class LegesConditionEvaluator
{
    /**
     * Evaluate a condition object. Every key in the condition must match
     * (logical AND). An empty condition object matches everything.
     *
     * Supported condition keys:
     *  - leeftijd: {min?: int, max?: int}        — derived from context['leeftijd']
     *  - oppervlakte: {min?: number, max?: number} — from case attributes
     *  - bouwsom: {min?: number, max?: number}    — from case attributes
     *  - spoedAanvraag: bool                       — from case attributes
     *  - huishoudinkomen: {max?: number}          — from context['huishoudinkomen']
     *  - herhaalaanvraag: {within_months: int}    — from context['herhaalaanvraag_maanden']
     *
     * @param array<string, mixed> $condities The condition object.
     * @param array<string, mixed> $caseData  The case attribute bag.
     * @param array<string, mixed> $context   Supplementary data (leeftijd, huishoudinkomen, herhaalaanvraag_maanden).
     *
     * @return bool True when every declared condition is satisfied.
     */
    public function evaluate(array $condities, array $caseData, array $context=[]): bool
    {
        if ($condities === []) {
            return true;
        }

        foreach ($condities as $key => $spec) {
            if ($this->evaluateOne(key: (string) $key, spec: $spec, caseData: $caseData, context: $context) === false) {
                return false;
            }
        }

        return true;
    }//end evaluate()

    /**
     * Evaluate a single condition key.
     *
     * @param string               $key      Condition key.
     * @param mixed                $spec     Condition specification.
     * @param array<string, mixed> $caseData Case attributes.
     * @param array<string, mixed> $context  Supplementary data.
     *
     * @return bool
     */
    private function evaluateOne(string $key, mixed $spec, array $caseData, array $context): bool
    {
        return match ($key) {
            'leeftijd' => $this->inRange(
                value: $this->numeric(value: ($context['leeftijd'] ?? null)),
                spec: $spec
            ),
            'oppervlakte' => $this->inRange(
                value: $this->numeric(value: ($caseData['oppervlakte'] ?? null)),
                spec: $spec
            ),
            'bouwsom' => $this->inRange(
                value: $this->numeric(value: ($caseData['bouwsom'] ?? null)),
                spec: $spec
            ),
            'huishoudinkomen' => $this->inRange(
                value: $this->numeric(value: ($context['huishoudinkomen'] ?? null)),
                spec: $spec
            ),
            'spoedAanvraag' => $this->matchesBool(
                value: ($caseData['spoedAanvraag'] ?? false),
                spec: $spec
            ),
            'herhaalaanvraag' => $this->matchesHerhaalaanvraag(spec: $spec, context: $context),
            // Unknown condition keys are treated as not satisfied so that an
            // unsupported rule never silently grants a discount.
            default => false,
        };//end match
    }//end evaluateOne()

    /**
     * Check whether a value falls inside an optional {min, max} range spec.
     *
     * A null value never satisfies a range (the underlying datum is missing).
     *
     * @param float|null $value The numeric value to test.
     * @param mixed      $spec  The range spec.
     *
     * @return bool
     */
    private function inRange(?float $value, mixed $spec): bool
    {
        if ($value === null || is_array($spec) === false) {
            return false;
        }

        if (isset($spec['min']) === true && $value < (float) $spec['min']) {
            return false;
        }

        if (isset($spec['max']) === true && $value > (float) $spec['max']) {
            return false;
        }

        return true;
    }//end inRange()

    /**
     * Match a boolean condition.
     *
     * @param mixed $value The actual value.
     * @param mixed $spec  The expected boolean.
     *
     * @return bool
     */
    private function matchesBool(mixed $value, mixed $spec): bool
    {
        return (bool) $value === (bool) $spec;
    }//end matchesBool()

    /**
     * Match a herhaalaanvraag (repeat-application) condition.
     *
     * The caller resolves the number of months since the applicant's most
     * recent comparable application into context['herhaalaanvraag_maanden'];
     * a null value means there was no prior application and the condition fails.
     *
     * @param mixed                $spec    Spec with within_months.
     * @param array<string, mixed> $context Supplementary data.
     *
     * @return bool
     */
    private function matchesHerhaalaanvraag(mixed $spec, array $context): bool
    {
        if (is_array($spec) === false || isset($spec['within_months']) === false) {
            return false;
        }

        $months = $this->numeric(value: ($context['herhaalaanvraag_maanden'] ?? null));
        if ($months === null) {
            return false;
        }

        return $months <= (float) $spec['within_months'];
    }//end matchesHerhaalaanvraag()

    /**
     * Coerce a value to a float, returning null when it is not numeric.
     *
     * @param mixed $value The value to coerce.
     *
     * @return float|null
     */
    private function numeric(mixed $value): ?float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (float) $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (float) $value;
        }

        return null;
    }//end numeric()
}//end class
