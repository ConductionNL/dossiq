<?php

/**
 * A stored JSON preference decodes to an array, and anything else to an empty one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\JsonPreference;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\JsonPreference
 */
class JsonPreferenceTest extends TestCase {
	/**
	 * JSON objects and lists decode; blanks, scalars and broken JSON give [].
	 *
	 * @return void
	 */
	public function testDecode(): void {
		$this->assertSame(['sort' => 'deadline', 'hidden' => ['mentions']], JsonPreference::decode('{"sort":"deadline","hidden":["mentions"]}'));
		$this->assertSame(['a', 'b'], JsonPreference::decode('["a","b"]'));
		$this->assertSame([], JsonPreference::decode('   '));
		$this->assertSame([], JsonPreference::decode('"deadline"'));
		$this->assertSame([], JsonPreference::decode('42'));
		$this->assertSame([], JsonPreference::decode('{broken'));
	}//end testDecode()
}//end class
