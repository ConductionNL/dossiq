<?php

/**
 * Unit test for FederatedShareTarget.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Sharing
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

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\Sharing\FederatedShareStore;
use OCA\Dossiq\Service\Sharing\FederatedShareTarget;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Sharing\FederatedShareTarget
 */
class FederatedShareTargetTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$federationService = new \stdClass();
		$store = new FederatedShareStore(objectService: new \stdClass(), register: '1', shareSchema: '2');

		$value = new FederatedShareTarget(federationService: $federationService, store: $store);

		$this->assertSame(expected: $federationService, actual: $value->federationService);
		$this->assertSame(expected: $store, actual: $value->store);
	}//end testEveryPropertyReadsBack()
}//end class
