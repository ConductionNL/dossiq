<?php

/**
 * Stub DB Result for Unit Tests
 *
 * Provides a concrete implementation of OCP\DB\IResult for use in unit tests
 * where the real Nextcloud database layer is unavailable.
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

use OCP\DB\IResult;

/**
 * Minimal concrete stub for OCP\DB\IResult.
 */
class StubDbResult implements IResult {

	/**
	 * The rows to return from fetchAll().
	 *
	 * @var array<array<string,mixed>>
	 */
	private array $rows;

	/**
	 * The single row to return from fetch().
	 *
	 * @var array<string,mixed>|false
	 */
	private array|false $singleRow;

	/**
	 * Constructor.
	 *
	 * @param array<array<string,mixed>> $rows Rows for fetchAll()
	 * @param array<string,mixed>|false $singleRow Row for fetch()
	 *
	 * @return void
	 */
	public function __construct(array $rows = [], array|false $singleRow = false) {
		$this->rows = $rows;
		$this->singleRow = $singleRow;
	}//end __construct()

	/**
	 * Fetch the next row as an associative array.
	 *
	 * @param int $fetchMode Fetch mode
	 *
	 * @return mixed
	 */
	public function fetch(int $fetchMode = \PDO::FETCH_ASSOC): mixed {
		return $this->singleRow;
	}//end fetch()

	/**
	 * Fetch all rows.
	 *
	 * @param int $fetchMode Fetch mode
	 *
	 * @return array<array<string,mixed>>
	 */
	public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): array {
		return $this->rows;
	}//end fetchAll()

	/**
	 * Fetch a single column from the next row (deprecated).
	 *
	 * @return mixed
	 */
	public function fetchColumn(): mixed {
		if ($this->singleRow === false) {
			return false;
		}

		return reset($this->singleRow);
	}//end fetchColumn()

	/**
	 * Fetch the first value of the next row, or false if no more rows.
	 *
	 * @return false|mixed
	 */
	public function fetchOne(): mixed {
		if ($this->singleRow === false) {
			return false;
		}

		return reset($this->singleRow);
	}//end fetchOne()

	/**
	 * Close the cursor.
	 *
	 * @return true
	 */
	public function closeCursor(): bool {
		return true;
	}//end closeCursor()

	/**
	 * Return the number of rows affected.
	 *
	 * @return int
	 */
	public function rowCount(): int {
		return count($this->rows);
	}//end rowCount()

}//end class
