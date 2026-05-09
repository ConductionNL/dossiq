<?php

/**
 * Stub Query Builder for Unit Tests
 *
 * Provides a minimal stub for OCP\DB\QueryBuilder\IQueryBuilder for use
 * in unit tests without a full Nextcloud installation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Stubs;

/**
 * Minimal stub for OCP\DB\QueryBuilder\IQueryBuilder.
 *
 * PHP cannot implement IQueryBuilder directly because it references
 * Doctrine DBAL types not present in this repo. Instead we provide a
 * duck-typed stub that satisfies all calls made by KpiAggregationService.
 */
class StubQueryBuilder
{

    /**
     * The DB result to return from executeQuery().
     *
     * @var StubDbResult
     */
    private StubDbResult $result;

    /**
     * The stub expression builder.
     *
     * @var StubExpressionBuilder
     */
    private StubExpressionBuilder $expr;

    /**
     * The stub function builder.
     *
     * @var StubFunctionBuilder
     */
    private StubFunctionBuilder $func;


    /**
     * Constructor.
     *
     * @param StubDbResult $result The result to return
     *
     * @return void
     */
    public function __construct(StubDbResult $result)
    {
        $this->result = $result;
        $this->expr   = new StubExpressionBuilder();
        $this->func   = new StubFunctionBuilder();
    }//end __construct()


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function select(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function selectAlias(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function from(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function innerJoin(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function where(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function andWhere(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function orWhere(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function groupBy(mixed ...$args): static { return $this; }


    /**
     * Return self for chaining.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return static
     */
    public function orderBy(mixed ...$args): static { return $this; }


    /**
     * Return a named parameter placeholder.
     *
     * @param mixed      $value       The value (ignored)
     * @param mixed|null $type        The type (ignored)
     * @param mixed|null $placeHolder The placeholder (ignored)
     *
     * @return string
     */
    public function createNamedParameter(mixed $value, mixed $type = null, mixed $placeHolder = null): string
    {
        return ':p';
    }//end createNamedParameter()


    /**
     * Return a function expression string.
     *
     * @param string $fn The function expression
     *
     * @return string
     */
    public function createFunction(string $fn): string
    {
        return $fn;
    }//end createFunction()


    /**
     * Return the expression builder stub.
     *
     * @return StubExpressionBuilder
     */
    public function expr(): StubExpressionBuilder
    {
        return $this->expr;
    }//end expr()


    /**
     * Return the function builder stub.
     *
     * @return StubFunctionBuilder
     */
    public function func(): StubFunctionBuilder
    {
        return $this->func;
    }//end func()


    /**
     * Execute the query and return the stub result.
     *
     * @return StubDbResult
     */
    public function executeQuery(): StubDbResult
    {
        return $this->result;
    }//end executeQuery()


}//end class
