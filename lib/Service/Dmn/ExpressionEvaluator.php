<?php

/**
 * Procest DMN Expression Evaluator
 *
 * A pure, closed, bounded evaluator for DMN-style "input entry" cell
 * expressions — comparisons, ranges, set membership, and bare-literal
 * equality. Every branch below is a fixed parse followed by a plain PHP
 * comparison; there is NO `eval()`, NO reflection, and NO dynamic code
 * execution of any kind, so rule authoring (which end users can do through
 * the settings UI) can never become a code-injection vector.
 *
 * Grammar (see openspec/changes/dmn-decision-tables/design.md Decision 2):
 *   ''  or  '-'          wildcard — always matches
 *   '"literal"'          explicit quoted literal (escapes wildcard collision)
 *   '< X' '<= X' '> X' '>= X' '= X' '!= X'   comparison, X coerced to type
 *   '[A..B]' '(A..B)' '[A..B)' '(A..B]'      inclusive/exclusive range
 *   'in (a,b,c)'         set membership (members may be quoted)
 *   'literal'            bare-literal equality
 *
 * @category Service
 * @package  OCA\Procest\Service\Dmn
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
 *
 * @spec openspec/changes/dmn-decision-tables/design.md#decision-2-expression-grammar-bounded-safe-subset-of-feel
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Dmn;

use DateTimeImmutable;
use Throwable;

/**
 * Pure grammar evaluator for decision-table rule cells.
 *
 * @spec openspec/changes/dmn-decision-tables/tasks.md#task-2.2
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — a closed grammar parser is branchy by nature; every branch is a fixed, tested form
 */
class ExpressionEvaluator
{

    /**
     * Declared input/output types this evaluator understands.
     *
     * @var string[]
     */
    public const VALID_TYPES = ['string', 'number', 'boolean', 'date'];

    /**
     * Check whether a rule cell expression matches an already-coerced value.
     *
     * @param string $expression The raw cell text (e.g. `'[0..25000]'`, `'-'`, `'in (a,b)'`).
     * @param mixed  $value      The runtime value, already coerced via {@see coerce()} for `$type`.
     * @param string $type       One of {@see VALID_TYPES}.
     *
     * @return bool True when the expression matches the value.
     *
     * @throws DecisionEvaluationException `invalid_expression` on malformed grammar,
     *                                      `type_mismatch` when a literal in the expression
     *                                      cannot be coerced to `$type`.
     *
     * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — one dispatch per grammar form; splitting hides the grammar
     * @SuppressWarnings(PHPMD.NPathComplexity)      — same: the branches are a flat form-dispatch, not nested logic
     */
    public static function matches(string $expression, mixed $value, string $type): bool
    {
        $trimmed = trim($expression);

        // Explicit quoted literal — bypasses the wildcard shortcut so a rule
        // author can match the literal string "-" by writing `"-"`.
        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && str_ends_with($trimmed, '"') === true) {
            $literal = self::unquote(raw: $trimmed);
            return self::equals(left: $value, right: self::coerce(value: $literal, type: $type), type: $type);
        }

        if ($trimmed === '' || $trimmed === '-') {
            return true;
        }

        if (preg_match('/^in\s*\((.*)\)$/is', $trimmed, $setMatch) === 1) {
            $members = self::parseSetMembers(inner: $setMatch[1]);
            foreach ($members as $member) {
                if (self::equals(left: $value, right: self::coerce(value: $member, type: $type), type: $type) === true) {
                    return true;
                }
            }

            return false;
        }

        if (preg_match('/^([\[(])\s*(.*?)\s*\.\.\s*(.*?)\s*([\])])$/s', $trimmed, $rangeMatch) === 1) {
            return self::matchesRange(match: $rangeMatch, value: $value, type: $type);
        }

        // Two-character operators BEFORE single-character ones (`<=` before `<`).
        foreach (['<=', '>=', '!='] as $operator) {
            if (str_starts_with($trimmed, $operator) === true) {
                return self::matchesComparison(operator: $operator, remainder: substr($trimmed, 2), value: $value, type: $type);
            }
        }

        foreach (['<', '>', '='] as $operator) {
            if (str_starts_with($trimmed, $operator) === true) {
                return self::matchesComparison(operator: $operator, remainder: substr($trimmed, 1), value: $value, type: $type);
            }
        }

        // Bare literal — plain equality.
        return self::equals(left: $value, right: self::coerce(value: $trimmed, type: $type), type: $type);
    }//end matches()

    /**
     * Coerce a raw scalar (runtime input or rule-cell literal) to `$type`.
     *
     * @param mixed  $value The raw value.
     * @param string $type  One of {@see VALID_TYPES}.
     *
     * @return string|float|bool|int The coerced value (int for `date`, a Unix timestamp).
     *
     * @throws DecisionEvaluationException `type_mismatch` when coercion fails.
     *
     * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
     */
    public static function coerce(mixed $value, string $type): string|float|bool|int
    {
        return match ($type) {
            'string' => self::coerceString(value: $value),
            'number' => self::coerceNumber(value: $value),
            'boolean' => self::coerceBoolean(value: $value),
            'date' => self::coerceDate(value: $value),
            default => throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['reason' => 'unsupported_type', 'type' => $type]),
        };
    }//end coerce()

    /**
     * Coerce to string.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     *
     * @throws DecisionEvaluationException `type_mismatch` for non-scalar input.
     */
    private static function coerceString(mixed $value): string
    {
        if (is_scalar($value) === false) {
            throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'string']);
        }

        return (string) $value;
    }//end coerceString()

    /**
     * Coerce to a float.
     *
     * @param mixed $value Raw value.
     *
     * @return float
     *
     * @throws DecisionEvaluationException `type_mismatch` for non-numeric input.
     */
    private static function coerceNumber(mixed $value): float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (float) $value;
        }

        if (is_string($value) === true && is_numeric(trim($value)) === true) {
            return (float) trim($value);
        }

        throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'number', 'value' => $value]);
    }//end coerceNumber()

    /**
     * Coerce to a bool.
     *
     * @param mixed $value Raw value.
     *
     * @return bool
     *
     * @throws DecisionEvaluationException `type_mismatch` for unrecognised input.
     */
    private static function coerceBoolean(mixed $value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_int($value) === true && ($value === 0 || $value === 1)) {
            return ($value === 1);
        }

        if (is_string($value) === true) {
            $lower = strtolower(trim($value));
            if ($lower === 'true') {
                return true;
            }

            if ($lower === 'false') {
                return false;
            }
        }

        throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'boolean', 'value' => $value]);
    }//end coerceBoolean()

    /**
     * Coerce to a Unix timestamp (int).
     *
     * @param mixed $value Raw value.
     *
     * @return int
     *
     * @throws DecisionEvaluationException `type_mismatch` for unparsable input.
     */
    private static function coerceDate(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) === true && trim($value) !== '') {
            try {
                return (new DateTimeImmutable(trim($value)))->getTimestamp();
            } catch (Throwable $e) {
                throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'date', 'value' => $value]);
            }
        }

        throw new DecisionEvaluationException(errorCode: 'type_mismatch', details: ['expected' => 'date', 'value' => $value]);
    }//end coerceDate()

    /**
     * Evaluate a parsed range match against a coerced value.
     *
     * @param array<int, string> $match Regex capture groups: [0]=full, [1]=open bracket, [2]=low, [3]=high, [4]=close bracket.
     * @param mixed              $value The already-coerced runtime value.
     * @param string             $type  Declared type.
     *
     * @return bool
     *
     * @throws DecisionEvaluationException `invalid_expression` on a missing bound, `type_mismatch` on an unparsable bound.
     */
    private static function matchesRange(array $match, mixed $value, string $type): bool
    {
        [, $open, $lowRaw, $highRaw, $close] = $match;
        if ($lowRaw === '' || $highRaw === '') {
            throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_range_bound']);
        }

        $low  = self::coerce(value: $lowRaw, type: $type);
        $high = self::coerce(value: $highRaw, type: $type);

        $lowOk = ($value > $low);
        if ($open === '[') {
            $lowOk = ($value >= $low);
        }

        $highOk = ($value < $high);
        if ($close === ']') {
            $highOk = ($value <= $high);
        }

        return ($lowOk === true && $highOk === true);
    }//end matchesRange()

    /**
     * Evaluate a comparison operator against a coerced value.
     *
     * @param string $operator  One of `< > <= >= = !=`.
     * @param string $remainder The raw operand text (before the leading whitespace is trimmed).
     * @param mixed  $value     The already-coerced runtime value.
     * @param string $type      Declared type.
     *
     * @return bool
     *
     * @throws DecisionEvaluationException `invalid_expression` when the operand is empty, `type_mismatch` when it cannot be coerced.
     */
    private static function matchesComparison(string $operator, string $remainder, mixed $value, string $type): bool
    {
        $operand = trim($remainder);
        if ($operand === '') {
            throw new DecisionEvaluationException(errorCode: 'invalid_expression', details: ['reason' => 'missing_operand', 'operator' => $operator]);
        }

        if (strlen($operand) >= 2 && $operand[0] === '"' && str_ends_with($operand, '"') === true) {
            $operand = self::unquote(raw: $operand);
        }

        $coerced = self::coerce(value: $operand, type: $type);

        return match ($operator) {
            '<' => ($value < $coerced),
            '<=' => ($value <= $coerced),
            '>' => ($value > $coerced),
            '>=' => ($value >= $coerced),
            '=' => self::equals(left: $value, right: $coerced, type: $type),
            '!=' => (self::equals(left: $value, right: $coerced, type: $type) === false),
            default => throw new DecisionEvaluationException(
                errorCode: 'invalid_expression',
                details: ['reason' => 'unknown_operator', 'operator' => $operator],
            ),
        };
    }//end matchesComparison()

    /**
     * Type-aware equality.
     *
     * @param mixed  $left  Left operand (already coerced).
     * @param mixed  $right Right operand (already coerced).
     * @param string $type  Declared type.
     *
     * @return bool
     */
    private static function equals(mixed $left, mixed $right, string $type): bool
    {
        if ($type === 'number' || $type === 'date') {
            return (abs(((float) $left) - ((float) $right)) < 1.0e-9);
        }

        return ($left === $right);
    }//end equals()

    /**
     * Split the inner text of `in (...)` into raw member strings, respecting
     * double-quoted members that may themselves contain commas.
     *
     * @param string $inner The text between the parentheses.
     *
     * @return array<int, string> Raw (still-quoted) member strings.
     */
    private static function parseSetMembers(string $inner): array
    {
        $members  = [];
        $buffer   = '';
        $inQuotes = false;
        $length   = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $buffer  .= $char;
                continue;
            }

            if ($char === ',' && $inQuotes === false) {
                $members[] = trim($buffer);
                $buffer    = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $members[] = trim($buffer);
        }

        return array_map(
            static function (string $member): string {
                if (strlen($member) >= 2 && $member[0] === '"' && str_ends_with($member, '"') === true) {
                    return self::unquote(raw: $member);
                }

                return $member;
            },
            $members,
        );
    }//end parseSetMembers()

    /**
     * Strip one layer of surrounding double quotes and unescape `\"`.
     *
     * @param string $raw The quoted raw text, e.g. `'"a b"'`.
     *
     * @return string
     */
    private static function unquote(string $raw): string
    {
        $inner = substr($raw, 1, -1);
        return str_replace('\\"', '"', $inner);
    }//end unquote()
}//end class
