<?php

/**
 * Stub Function Builder for Unit Tests
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
 * Duck-typed stub for the function builder returned by IQueryBuilder::func().
 */
class StubFunctionBuilder
{

    /**
     * Stub count function.
     *
     * @param mixed ...$args Arguments (ignored)
     *
     * @return string
     */
    public function count(mixed ...$args): string { return 'COUNT(*)'; }


}//end class
