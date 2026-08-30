<?php

/**
 * Stub Expression Builder for Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Stubs;

/**
 * Duck-typed stub for the expression builder returned by IQueryBuilder::expr().
 */
class StubExpressionBuilder {

	/**
	 * Stub eq expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function eq(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub like expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function like(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub lt expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function lt(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub isNull expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function isNull(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub isNotNull expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function isNotNull(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub orX expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function orX(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub andX expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function andX(mixed ...$args): string {
		return '1=1';
	}

	/**
	 * Stub in expression.
	 *
	 * @param mixed ...$args Arguments (ignored)
	 *
	 * @return string
	 */
	public function in(mixed ...$args): string {
		return '1=1';
	}

}//end class
