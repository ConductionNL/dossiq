<?php

/**
 * RoutedMessage unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\RoutedMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RoutedMessage.
 *
 * @covers \OCA\Dossiq\Service\Intake\RoutedMessage
 */
class RoutedMessageTest extends TestCase {

	/**
	 * Every property reads back what the constructor was given.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$value = new RoutedMessage(
			message: ['channelId' => 'mail', 'externalId' => 'm-1'],
			payload: ['caseType' => 'ct-1'],
			ruleName: 'rule-a',
			channel: 'mail',
			externalId: 'm-1'
		);

		$this->assertSame(expected: ['channelId' => 'mail', 'externalId' => 'm-1'], actual: $value->message);
		$this->assertSame(expected: ['caseType' => 'ct-1'], actual: $value->payload);
		$this->assertSame(expected: 'rule-a', actual: $value->ruleName);
		$this->assertSame(expected: 'mail', actual: $value->channel);
		$this->assertSame(expected: 'm-1', actual: $value->externalId);
	}//end testPropertiesReadBack()
}//end class
