<?php

/**
 * Doctrine DBAL Stubs for Unit Tests
 *
 * Provides minimal stub classes for Doctrine DBAL types referenced by
 * the nextcloud/ocp IDBConnection and IQueryBuilder interfaces. These
 * stubs allow PHPUnit to mock OCP interfaces without a real Nextcloud
 * or Doctrine DBAL installation.
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

// Doctrine DBAL stubs.

namespace Doctrine\DBAL {
	if (class_exists(\Doctrine\DBAL\ParameterType::class) === false) {
		class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const BINARY = 3;
			public const BOOLEAN = 5;
			public const LARGE_OBJECT = 6;
		}
	}

	if (class_exists(\Doctrine\DBAL\ArrayParameterType::class) === false) {
		class ArrayParameterType {
			public const STRING = 101;
			public const INTEGER = 102;
			public const ASCII = 103;
		}
	}

	if (class_exists(\Doctrine\DBAL\Connection::class) === false) {
		class Connection {
		}
	}

	if (class_exists(\Doctrine\DBAL\Exception::class) === false) {
		class Exception extends \RuntimeException {
		}
	}
}

namespace Doctrine\DBAL\Schema {
	if (class_exists(\Doctrine\DBAL\Schema\Schema::class) === false) {
		class Schema {
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (class_exists(\Doctrine\DBAL\Types\Types::class) === false) {
		class Types {
			public const STRING = 'string';
			public const INTEGER = 'integer';
			public const BOOLEAN = 'boolean';
			public const FLOAT = 'float';
			public const TEXT = 'text';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATE_MUTABLE = 'date';
			public const TIME_MUTABLE = 'time';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const TIME_IMMUTABLE = 'time_immutable';
			public const BIGINT = 'bigint';
			public const SMALLINT = 'smallint';
			public const DECIMAL = 'decimal';
			public const JSON = 'json';
			public const BINARY = 'binary';
			public const BLOB = 'blob';
			public const ASCII_STRING = 'ascii_string';
			public const GUID = 'guid';
		}
	}

	if (class_exists(\Doctrine\DBAL\Types\Type::class) === false) {
		abstract class Type {
		}
	}
}

namespace Doctrine\DBAL\Platforms {
	if (class_exists(\Doctrine\DBAL\Platforms\AbstractPlatform::class) === false) {
		abstract class AbstractPlatform {
		}
	}
}

namespace Doctrine\DBAL\Logging {
	if (interface_exists(\Doctrine\DBAL\Logging\SQLLogger::class) === false) {
		interface SQLLogger {
		}
	}
}

namespace Doctrine\DBAL\Query\Expression {
	if (class_exists(\Doctrine\DBAL\Query\Expression\ExpressionBuilder::class) === false) {
		class ExpressionBuilder {
			public const EQ = '=';
			public const NEQ = '<>';
			public const LT = '<';
			public const LTE = '<=';
			public const GT = '>';
			public const GTE = '>=';
		}
	}
}

// OCP DB QueryBuilder stubs — needed before OCP\IDBConnection is loaded.

namespace OCP\DB\QueryBuilder {
	if (interface_exists(\OCP\DB\QueryBuilder\ICompositeExpression::class) === false) {
		interface ICompositeExpression {
			public function addMultiple(array $parts = []): ICompositeExpression;
			public function add(mixed $part): ICompositeExpression;
			public function count(): int;
			public function getType(): string;
		}
	}

	if (interface_exists(\OCP\DB\QueryBuilder\IQueryFunction::class) === false) {
		interface IQueryFunction {
			public function __toString(): string;
		}
	}
}

// OC internal stubs.

namespace OC\DB\QueryBuilder\Sharded {
	if (class_exists(\OC\DB\QueryBuilder\Sharded\ShardDefinition::class) === false) {
		class ShardDefinition {
		}
	}

	if (class_exists(\OC\DB\QueryBuilder\Sharded\CrossShardMoveHelper::class) === false) {
		class CrossShardMoveHelper {
		}
	}
}

// Root OC stub — OCP\AppFramework\Http\Response::getHeaders() calls \OC::$server->get().

namespace {
	if (class_exists('OC') === false) {
		class OC {
			/**
			 * Minimal server stub that returns null for any service.
			 *
			 * @var object
			 */
			public static object $server;

			/**
			 * Bootstrap: initialise the static $server property.
			 *
			 * @return void
			 */
			public static function bootstrapServer(): void {
				self::$server = new class {
					/**
					 * Return a minimal stub for any requested service.
					 *
					 * Returns an IRequest-compatible stub (with getId()) so that
					 * OCP\AppFramework\Http\Response::getHeaders() does not throw.
					 *
					 * @param string $class The service class name (ignored)
					 *
					 * @return object
					 */
					public function get(string $class): object {
						return new class {
							/** @return string */
							public function getId(): string {
								return 'test-request-id';
							}
							/** @return mixed */
							public function __call(string $n, array $a): mixed {
								return null;
							}
						};
					}

					/**
					 * Return a minimal stub for any method call (magic fallback).
					 *
					 * @param string $name Method name
					 * @param array $arguments Arguments
					 *
					 * @return object
					 */
					public function __call(string $name, array $arguments): object {
						return new class {
							/** @return mixed */
							public function __call(string $n, array $a): mixed {
								return null;
							}
						};
					}
				};
			}
		}

		OC::bootstrapServer();
	}//end if
}
