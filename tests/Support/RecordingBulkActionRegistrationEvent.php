<?php

/**
 * A bulk-action registration event a test can read back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Event\BulkActionRegistrationEvent;

/**
 * The stub event, plus a reader that exists only for the tests.
 *
 * OpenRegister's real `BulkActionRegistrationEvent` hands every action straight
 * to a `BulkActionRegistry` and offers nothing to read them back with. A test
 * still has to see what dossiq's listener registered, so the reader lives here,
 * on a subclass in dossiq's own namespace, rather than on the stub. Keeping it
 * off the stub is the whole point: `StubApiDriftTest` compares public API, and
 * a public reader on the stub would be a method dossiq could write against and
 * never find against a real OpenRegister.
 */
final class RecordingBulkActionRegistrationEvent extends BulkActionRegistrationEvent {

	/**
	 * What the listener registered, in the order it registered it.
	 *
	 * @return array<int, BulkActionInterface> The registered actions.
	 */
	public function recordedActions(): array {
		return $this->actions;
	}//end recordedActions()
}//end class
