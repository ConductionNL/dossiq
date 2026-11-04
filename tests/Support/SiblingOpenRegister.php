<?php

/**
 * The OpenRegister checkout beside this app, for tests that run its real code.
 *
 * The shared PHPUnit job clones openregister to `server/apps/openregister`,
 * beside `server/apps/dossiq`, and installs its dependencies before the suite
 * runs; a local checkout follows the same layout. A test that needs it fails
 * when it is absent, naming the path it looked at.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Paths into the sibling OpenRegister checkout.
 */
final class SiblingOpenRegister {

	/**
	 * The checkout, relative to this file.
	 *
	 * @var string
	 */
	public const ROOT = __DIR__ . '/../../../openregister';

	/**
	 * The absolute path of a file or directory in the checkout.
	 *
	 * @param string $relative Path inside the checkout, e.g. `lib` or `vendor/autoload.php`.
	 *
	 * @return string The resolved path; the calling test fails when it does not exist.
	 */
	public static function path(string $relative): string {
		$path = realpath(self::ROOT . '/' . $relative);
		if ($path === false) {
			Assert::fail(
				'No ' . $relative . ' in the openregister checkout at ' . self::ROOT
				. '. Clone openregister beside this app and run composer install in it.'
			);
		}

		return $path;
	}//end path()

	/**
	 * Load one OpenRegister namespace from the checkout, ahead of the stubs.
	 *
	 * Call it only in a test that runs in a process of its own: the real
	 * classes stay loaded for the rest of the process.
	 *
	 * @param string $namespace Namespace under `OCA\OpenRegister\`, e.g. `Service\ShippedBaseline`; '' for all of it.
	 *
	 * @return void
	 */
	public static function loadNamespace(string $namespace): void {
		$sub = trim($namespace, '\\');
		$prefix = rtrim('OCA\\OpenRegister\\' . $sub, '\\') . '\\';
		$dir = self::path(relative: rtrim('lib/' . str_replace('\\', '/', $sub), '/'));
		spl_autoload_register(
			static function (string $class) use ($prefix, $dir): void {
				if (str_starts_with($class, $prefix) === true) {
					$file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
					if (is_file($file) === true) {
						require_once $file;
					}
				}
			},
			true,
			true
		);
	}//end loadNamespace()
}//end class
