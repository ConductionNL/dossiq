<?php

/**
 * Test stub for OpenRegister's SchemaApplicationMigrator.
 *
 * MigrateRegisterApplicationId resolves this class BY NAME (dossiq must boot on
 * an instance without OpenRegister, so there is no import to point at). A stub
 * declared under the real FQN is therefore the only way to exercise the
 * repair step's branches; without it class_exists() is false and every test
 * would take the "not available" path and prove nothing about the rest.
 *
 * Guarded by class_exists so a real OpenRegister on the include path always
 * wins over this.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

if (class_exists('OCA\OpenRegister\Service\SchemaApplicationMigrator', false) === false) {
	/**
	 * Minimal stand-in for the real migrator.
	 */
	class SchemaApplicationMigrator {
		/**
		 * The canned outcome returned by migrate().
		 *
		 * @var array<string, mixed>
		 */
		public static array $outcome = [
			'ok' => true,
			'reason' => 'migrated',
			'collisions' => [],
			'schemas' => 0,
			'registers' => 0,
		];

		/**
		 * Arguments the step passed, for assertion.
		 *
		 * @var array<int, string>
		 */
		public static array $calledWith = [];

		/**
		 * Whether migrate() should throw.
		 *
		 * @var bool
		 */
		public static bool $throws = false;

		/**
		 * Record the call and return the canned outcome.
		 *
		 * @param string $from The current application id.
		 * @param string $to The new application id.
		 *
		 * @return array<string, mixed> The canned outcome.
		 */
		public function migrate(string $from, string $to): array {
			self::$calledWith = [$from, $to];

			if (self::$throws === true) {
				throw new \RuntimeException('database is on fire');
			}

			return self::$outcome;
		}//end migrate()

	}//end class
}//end if
