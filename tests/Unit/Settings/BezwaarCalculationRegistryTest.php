<?php

/**
 * Procest Bezwaar Calculation Registry Test
 *
 * Verifies that the bezwaar schema's `x-openregister-calculations` annotation
 * in `lib/Settings/procest_register.json` is declared in the engine-compatible
 * shape (a field-keyed MAP of AST expressions with `materialise` flags), that
 * every operator it uses is in OpenRegister's supported operator set, and that
 * the declared AWB 7:10 / 4:17 expressions compute the legally correct
 * beslistermijn dates and dwangsom amounts against worked examples.
 *
 * Before 2026-07 this annotation was an ARRAY of objects carrying string-DSL
 * expressions ("addWeeks($.ontvangstdatum, 6)"). OpenRegister's calculation
 * engine only honours a field-keyed map of AST expressions — the array form
 * was silently inert, so the statutory objection deadline and the dwangsom
 * penalty were never computed despite reading as a live compliance artifact
 * (procest#223 inert-declaration finding 4).
 *
 * The evaluator below mirrors the subset of
 * OCA\OpenRegister\Service\Calculation\CalculationEvaluator semantics
 * (dateAdd / dateDiff / min / max / + / - / * / coalesce / prop / now) that the
 * bezwaar expressions use, so the assertions exercise the ACTUAL AST read from
 * the register file — not a hand-copied duplicate.
 *
 * @category Tests
 * @package  OCA\Procest\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Bezwaar declarative-calculation registry conformance + arithmetic proof.
 */
final class BezwaarCalculationRegistryTest extends TestCase
{
    /**
     * OpenRegister CalculationEvaluator's VALID_OPS allowlist (origin/development).
     * Mirrored here so the test fails if the register ever uses an operator the
     * engine would reject at schema-save time.
     *
     * @var array<int, string>
     */
    private const OR_VALID_OPS = [
        'prop', 'lit', 'concat', 'if', 'not', 'and', 'or',
        '+', '-', '*', '/', '%',
        'eq', 'ne', 'lt', 'lte', 'gt', 'gte',
        'now', 'diffDays', 'formatDate', 'dateDiff', 'dateAdd', 'sequence',
        'max', 'min', 'coalesce', 'abs', 'round', 'year', 'monthsElapsed', 'sha256',
    ];

    /**
     * The bezwaar calculations map, loaded from the register file.
     *
     * @var array<string, mixed>
     */
    private array $calcs;

    /**
     * Load the bezwaar calculations from the register JSON.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__.'/../../../lib/Settings/procest_register.json';
        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json, 'register JSON must parse');
        $bezwaar = $json['components']['schemas']['bezwaar'] ?? null;
        $this->assertIsArray($bezwaar, 'bezwaar schema must exist');
        $this->calcs = $bezwaar['x-openregister-calculations'] ?? [];
    }//end setUp()

    /**
     * The annotation must be a field-keyed MAP (not a positional array), the
     * exact shape the engine iterates as `foreach ($calcs as $name => $spec)`.
     *
     * @return void
     */
    public function testCalculationsAreAFieldKeyedMap(): void
    {
        $this->assertNotEmpty($this->calcs, 'bezwaar must declare calculations');
        $this->assertFalse(
            array_is_list($this->calcs),
            'x-openregister-calculations must be a field-keyed map, not an array (the inert legacy shape)'
        );
        foreach (['decisionDeadline', 'dwangsom'] as $field) {
            $this->assertArrayHasKey($field, $this->calcs, "calculation '$field' must be keyed by output field");
            $spec = $this->calcs[$field];
            $this->assertIsArray($spec['expression'] ?? null, "'$field' expression must be an AST object, not a string DSL");
            $this->assertArrayHasKey('materialise', $spec, "'$field' must declare a materialise flag");
            $this->assertIsBool($spec['materialise'], "'$field' materialise must be boolean");
        }

        // decisionDeadline is not time-dependent -> materialised & filterable.
        $this->assertTrue($this->calcs['decisionDeadline']['materialise'], 'decisionDeadline must be materialised');
        // dwangsom references now() -> virtual (read-time) so it never goes stale.
        $this->assertFalse($this->calcs['dwangsom']['materialise'], 'dwangsom must be virtual (time-dependent)');
    }//end testCalculationsAreAFieldKeyedMap()

    /**
     * Every operator used must be in OpenRegister's VALID_OPS, or the schema
     * save would fail calculation-unknown-op validation.
     *
     * @return void
     */
    public function testOnlyEngineSupportedOperatorsAreUsed(): void
    {
        foreach ($this->calcs as $field => $spec) {
            foreach ($this->collectOps($spec['expression']) as $op) {
                $this->assertContains(
                    $op,
                    self::OR_VALID_OPS,
                    "calculation '$field' uses operator '$op' which OpenRegister does not support"
                );
            }
        }
    }//end testOnlyEngineSupportedOperatorsAreUsed()

    /**
     * Worked AWB 7:10 example: ontvangstdatum + 6 weeks + verdaging + opschorting.
     *
     * @return void
     */
    public function testDecisionDeadlineMatchesWorkedAwbExample(): void
    {
        $expr = $this->calcs['decisionDeadline']['expression'];

        // No verdaging / opschorting: 2026-01-01 + 6 weeks (42 days) = 2026-02-12.
        $base = $this->eval($expr, ['ontvangstdatum' => '2026-01-01', 'verdagingsperiode' => 0, 'opschorting' => 0]);
        $this->assertSame('2026-02-12', $base);
        $this->assertSame(
            (new DateTimeImmutable('2026-01-01'))->modify('+42 days')->format('Y-m-d'),
            $base
        );

        // Standard 6-week verdaging (42 days) + 7 days opschorting:
        // 2026-01-01 + 42 + 42 + 7 = 2026-01-01 + 91 days.
        $extended = $this->eval($expr, ['ontvangstdatum' => '2026-01-01', 'verdagingsperiode' => 42, 'opschorting' => 7]);
        $this->assertSame(
            (new DateTimeImmutable('2026-01-01'))->modify('+91 days')->format('Y-m-d'),
            $extended
        );
        $this->assertSame('2026-04-02', $extended);
    }//end testDecisionDeadlineMatchesWorkedAwbExample()

    /**
     * The common case: a bezwaar filed with no verdaging and no opschorting,
     * where those optional fields are simply ABSENT from the payload (OR's
     * saveObject is PUT-semantic, so a partial update omits them and the
     * schema default never lands).
     *
     * OpenRegister's dateAdd returns NULL for a non-numeric amount, so a bare
     * `{prop: verdagingsperiode}` would null the ENTIRE statutory deadline —
     * a missing AWB 7:10 beslistermijn on an ordinary objection. The coalesce
     * guards against that. Without it this test returns null.
     *
     * @return void
     */
    public function testDecisionDeadlineSurvivesAbsentOptionalFields(): void
    {
        $expr = $this->calcs['decisionDeadline']['expression'];

        // Only the required field is present — no verdagingsperiode/opschorting keys at all.
        $deadline = $this->eval($expr, ['ontvangstdatum' => '2026-01-01']);

        $this->assertNotNull(
            $deadline,
            'a bezwaar without verdaging/opschorting MUST still get its AWB 7:10 deadline'
        );
        $this->assertSame('2026-02-12', $deadline, 'ontvangstdatum + 6 weeks');
    }//end testDecisionDeadlineSurvivesAbsentOptionalFields()

    /**
     * Worked AWB 4:17 example: tiered dwangsom accrual with EUR1442 plafond.
     *
     * @return void
     */
    public function testDwangsomMatchesWorkedAwbExample(): void
    {
        $expr = $this->calcs['dwangsom']['expression'];
        $now  = new DateTimeImmutable('2026-03-01T00:00:00+00:00');

        // No ingebrekestelling -> no penalty clock -> EUR0 (not the plafond).
        $none = $this->eval($expr, ['ingebrekestelling' => null], $now);
        $this->assertSame(0.0, (float) $none, 'null ingebrekestelling must yield EUR0, never the EUR1442 cap');

        // Ingebrekestelling 2026-02-25: grace ends 2026-03-11 -> at 2026-03-01 the
        // grace has not lapsed -> EUR0.
        $withinGrace = $this->eval($expr, ['ingebrekestelling' => '2026-02-25'], $now);
        $this->assertSame(0.0, (float) $withinGrace, 'within the 14-day grace no dwangsom accrues');

        // Ingebrekestelling 2026-01-25: grace ends 2026-02-08; at 2026-03-01 that
        // is 21 penalty days -> tier1 14*23=322 + tier2 7*35=245 = EUR567.
        $midTier = $this->eval($expr, ['ingebrekestelling' => '2026-01-25'], $now);
        $this->assertSame(567.0, (float) $midTier, '21 penalty days = 322 + 245 = 567');

        // Ingebrekestelling 2026-01-01: grace ends 2026-01-15; at 2026-03-01 that
        // is 45 penalty days -> past 42 -> plafond EUR1442.
        $capped = $this->eval($expr, ['ingebrekestelling' => '2026-01-01'], $now);
        $this->assertSame(1442.0, (float) $capped, '45 penalty days exceeds 42 -> capped at EUR1442');
    }//end testDwangsomMatchesWorkedAwbExample()

    /**
     * Recursively collect operator names from an AST expression.
     *
     * @param mixed $expr Expression node.
     *
     * @return array<int, string>
     */
    private function collectOps(mixed $expr): array
    {
        if (is_array($expr) === false || $expr === []) {
            return [];
        }

        // A list or a multi-key named-parameter dict (e.g. dateAdd's
        // {date, amount, unit}) is a container of sub-nodes, not an operator node.
        if (array_is_list($expr) === true || count($expr) !== 1) {
            $ops = [];
            foreach ($expr as $sub) {
                $ops = array_merge($ops, $this->collectOps($sub));
            }

            return $ops;
        }

        // A single-key associative array is an operator node.
        $op  = (string) array_key_first($expr);
        return array_merge([$op], $this->collectOps($expr[$op]));
    }//end collectOps()

    /**
     * Evaluate an AST expression against an object, mirroring OpenRegister's
     * CalculationEvaluator semantics for the operators the bezwaar calcs use.
     *
     * @param mixed                $expr   Expression node.
     * @param array<string, mixed> $object Object payload.
     * @param DateTimeImmutable    $now    Injected "now" for time-dependent ops.
     *
     * @return mixed
     */
    private function eval(mixed $expr, array $object, ?DateTimeImmutable $now=null): mixed
    {
        $now = ($now ?? new DateTimeImmutable('now'));
        if (is_array($expr) === false || $expr === [] || array_is_list($expr) === true) {
            return $expr;
            // Scalar literal.
        }

        $op   = (string) array_key_first($expr);
        $args = $expr[$op];

        switch ($op) {
            case 'prop':
                return ($object[(string) $args] ?? null);
            case 'now':
                return $now;
            case '+':
                $sum = 0;
                foreach ($args as $a) {
                    $sum += (float) $this->eval($a, $object, $now);
                }

                return $sum;
            case '-':
                return ((float) $this->eval($args[0], $object, $now) - (float) $this->eval($args[1], $object, $now));
            case '*':
                $prod = 1;
                foreach ($args as $a) {
                    $prod *= (float) $this->eval($a, $object, $now);
                }

                return $prod;
            case 'min':
            case 'max':
                $result = null;
                foreach ($args as $a) {
                    $v = $this->eval($a, $object, $now);
                    if ($v === null) {
                        continue;
                    }

                    $num = ($v + 0);
                    if ($result === null
                        || ($op === 'max' && $num > $result)
                        || ($op === 'min' && $num < $result)
                    ) {
                        $result = $num;
                    }
                }

                return $result;
            case 'coalesce':
                foreach ($args as $a) {
                    $v = $this->eval($a, $object, $now);
                    if ($v !== null) {
                        return $v;
                    }
                }

                return null;
            case 'dateAdd':
                $date = $this->toDate($this->eval($args['date'], $object, $now));
                if ($date === null) {
                    return null;
                }

                // Mirror OpenRegister's intervalFromAmountUnit() EXACTLY: a
                // non-numeric amount yields no interval, so dateAdd returns
                // null. Casting null to 0 here (as this mirror originally did)
                // is strictly more lenient than the engine and would hide a
                // nulled-out statutory deadline behind a green test.
                $amount = $this->eval($args['amount'], $object, $now);
                if (is_numeric($amount) === false) {
                    return null;
                }

                $amount = (int) $amount;
                $days   = ($args['unit'] === 'weeks') ? ($amount * 7) : $amount;
                return $date->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
            case 'dateDiff':
                $from = $this->toDate($this->eval($args['from'], $object, $now));
                $to   = $this->toDate($this->eval($args['to'], $object, $now));
                if ($from === null || $to === null) {
                    return null;
                }

                return intdiv(($to->getTimestamp() - $from->getTimestamp()), 86400);
            default:
                $this->fail("evaluator does not model operator '$op'");
        }//end switch
    }//end eval()

    /**
     * Coerce a value to a DateTimeImmutable, or null.
     *
     * @param mixed $v Value (DateTimeImmutable or Y-m-d string).
     *
     * @return DateTimeImmutable|null
     */
    private function toDate(mixed $v): ?DateTimeImmutable
    {
        if ($v instanceof DateTimeImmutable) {
            return $v;
        }

        if (is_string($v) === true && $v !== '') {
            try {
                return new DateTimeImmutable($v);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }//end toDate()
}//end class
