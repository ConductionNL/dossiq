<?php

/**
 * Unit test for FederatedShareRequest.
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

use OCA\Dossiq\Service\Sharing\FederatedShareRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Sharing\FederatedShareRequest
 */
class FederatedShareRequestTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$caseId = 'caseId-value';
		$remoteCloudId = 'remoteCloudId-value';
		$sharedFields = ['sharedFields' => 2];
		$sharedDocuments = ['sharedDocuments' => 3];
		$permissionLevel = 'permissionLevel-value';
		$createdBy = 'createdBy-value';

		$value = new FederatedShareRequest(
			caseId: $caseId,
			remoteCloudId: $remoteCloudId,
			sharedFields: $sharedFields,
			sharedDocuments: $sharedDocuments,
			permissionLevel: $permissionLevel,
			createdBy: $createdBy,
		);

		$this->assertSame(expected: $caseId, actual: $value->caseId);
		$this->assertSame(expected: $remoteCloudId, actual: $value->remoteCloudId);
		$this->assertSame(expected: $sharedFields, actual: $value->sharedFields);
		$this->assertSame(expected: $sharedDocuments, actual: $value->sharedDocuments);
		$this->assertSame(expected: $permissionLevel, actual: $value->permissionLevel);
		$this->assertSame(expected: $createdBy, actual: $value->createdBy);
	}//end testEveryPropertyReadsBack()
}//end class
