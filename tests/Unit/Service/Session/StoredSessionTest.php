<?php

/**
 * A stored session carries what it was read with.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Session
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Session;

use OCA\Dossiq\Service\Session\SessionDefinition;
use OCA\Dossiq\Service\Session\StoredSession;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\Session\StoredSession
 */
class StoredSessionTest extends TestCase {

	/**
	 * A fresh stored session has no register, id or record yet.
	 *
	 * @return void
	 */
	public function testStartsEmpty(): void {
		$session = new StoredSession();

		self::assertSame('', $session->register);
		self::assertSame('', $session->id);
		self::assertSame([], $session->current);
	}//end testStartsEmpty()

	/**
	 * What is set on it reads back unchanged.
	 *
	 * @return void
	 */
	public function testKeepsWhatIsSet(): void {
		$objectService = new stdClass();
		$definition = new SessionDefinition(config: ['key' => 'talk']);

		$session = new StoredSession();
		$session->objectService = $objectService;
		$session->register = 'dossiq';
		$session->id = 's-1';
		$session->current = ['case' => 'c-1'];
		$session->definition = $definition;

		self::assertSame($objectService, $session->objectService);
		self::assertSame('dossiq', $session->register);
		self::assertSame('s-1', $session->id);
		self::assertSame(['case' => 'c-1'], $session->current);
		self::assertSame($definition, $session->definition);
	}//end testKeepsWhatIsSet()
}//end class
