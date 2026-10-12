<?php

/**
 * HostResolver Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Outbound;

use OCA\Dossiq\Service\Outbound\HostResolver;
use PHPUnit\Framework\TestCase;

/**
 * The real name lookup, on the two names that answer the same everywhere.
 *
 * @covers \OCA\Dossiq\Service\Outbound\HostResolver
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class HostResolverTest extends TestCase {
	/**
	 * `localhost` is answered by the system resolver, with loopback and without duplicates.
	 *
	 * @return void
	 */
	public function testLocalhostAnswersWithLoopback(): void {
		$addresses = (new HostResolver())->addressesOf(host: 'localhost');

		$this->assertContains('127.0.0.1', $addresses);
		$this->assertSame(array_values(array_unique($addresses)), $addresses);
		foreach ($addresses as $address) {
			$this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP), $address);
		}
	}//end testLocalhostAnswersWithLoopback()

	/**
	 * A name under the reserved .invalid domain answers with nothing, and without a PHP warning.
	 *
	 * @return void
	 */
	public function testANameNothingAnswersForGivesAnEmptyList(): void {
		$resolver = new HostResolver();

		$this->assertSame([], $resolver->addressesOf(host: 'nothing.dossiq-test.invalid'));
		$this->assertIsString($resolver->lastWarning());
	}//end testANameNothingAnswersForGivesAnEmptyList()
}//end class
