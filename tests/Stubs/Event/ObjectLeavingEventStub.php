<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectLeavingEvent.
 *
 * Loaded by tests/bootstrap.php when the real class is absent. OpenRegister
 * dispatches it before an object moves out of its register and schema; it is a
 * deleting event to a guard, as in OpenRegister itself.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Event
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

namespace OCA\OpenRegister\Event;

if (class_exists(ObjectLeavingEvent::class) === false) {
	/**
	 * Stub class for ObjectLeavingEvent, used only in standalone unit tests.
	 */
	class ObjectLeavingEvent extends ObjectDeletingEvent {
	}
}
