<?php

/**
 * OC Internal Stubs for Unit Tests
 *
 * Provides minimal stubs for OC\\ internal interfaces that the nextcloud/ocp
 * package references but does not ship. Without these stubs PHPUnit cannot
 * mock OCP\\Files\\IRootFolder (which extends OC\\Hooks\\Emitter).
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

// phpcs:disable

namespace OC\Hooks {
	if (interface_exists(\OC\Hooks\Emitter::class) === false) {
		interface Emitter {
		}
	}
}

namespace OC\User {
	if (class_exists(\OC\User\NoUserException::class) === false) {
		class NoUserException extends \Exception {
		}
	}
}
