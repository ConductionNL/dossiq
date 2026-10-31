<?php

/**
 * Portal case steps test
 *
 * A person reading a case under a mandate is signed in as themselves, and
 * OpenRegister's runAsSystem lifts RBAC only for a request without a user, so
 * the case must be read unscoped or its steps come back empty.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#requirement-a-case-read-under-a-mandate-shows-its-steps-and-documents-req-portal-023
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseSteps;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Portal\PortalCaseSteps
 * @uses   \OCA\Dossiq\Portal\CaseSteps
 * @uses   \OCA\Dossiq\Service\Support\UnscopedObjectReader
 * @uses   \OCA\Dossiq\Service\Transitions\StatusPublicLabels
 */
class PortalCaseStepsTest extends TestCase {
	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	/**
	 * The `[_rbac, _multitenancy]` of every find.
	 *
	 * @var array<int, array{0: bool, 1: bool}>
	 */
	private array $findScopes = [];

	/**
	 * The service over an object service that, like OpenRegister for a
	 * signed-in user who is not the case's party, finds the case only unscoped.
	 *
	 * @return PortalCaseSteps
	 */
	private function service(): PortalCaseSteps {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->method('find')->willReturnCallback(function ($id, $_extend = [], $files = false, $register = null, $schema = null, $_rbac = true, $_multitenancy = true) {
			$this->findScopes[] = [$_rbac, $_multitenancy];
			if ($id !== self::CASE_ID || $_rbac !== false || $_multitenancy !== false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn(['id' => self::CASE_ID, 'caseType' => 'ct-1', 'status' => 's1']);
			return $entity;
		});
		$objects->method('searchObjectsBySlug')->willReturn(
			[
				['id' => 's1', 'order' => 1, 'name' => 'Ontvangen', 'publicLabel' => 'Ontvangen'],
				['id' => 's2', 'order' => 2, 'name' => 'In behandeling', 'publicLabel' => 'In behandeling'],
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'status_type_schema' => 'statusType',
		][$key] ?? '');

		return new PortalCaseSteps($settings, new NullLogger());
	}//end service()

	/**
	 * The case is read unscoped, so a mandated reader gets its steps.
	 *
	 * @return void
	 */
	public function testAMandatedReaderGetsTheStepsOfTheCase(): void {
		$steps = $this->service()->forCase(self::CASE_ID);

		$this->assertSame(['Ontvangen', 'In behandeling'], array_column($steps, 'label'));
		$this->assertSame(['current', 'todo'], array_column($steps, 'state'));
		$this->assertSame([[false, false]], $this->findScopes);
	}//end testAMandatedReaderGetsTheStepsOfTheCase()
}//end class
