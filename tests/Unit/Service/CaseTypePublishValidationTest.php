<?php

/**
 * Publishing refuses a handling switch that no reader reads.
 *
 * A switch declared on a case type and read by nothing is a promise the product
 * does not keep, and it is invisible until somebody relies on it. This pins the
 * refusal and, just as importantly, that a switch which IS read publishes fine.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Access\CaseFieldRoleProjector;
use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypePublishService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector;
use OCA\Dossiq\Service\Status\StatusFieldRuleDeclaration;
use OCA\Dossiq\Service\UnreadTriggerService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the handling-switch half of CaseTypePublishService::validate().
 *
 * @covers \OCA\Dossiq\Service\CaseTypePublishService
 *
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\CaseTypeAcknowledgement
 * @uses \OCA\Dossiq\Service\CaseTypeResolver
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\UnreadTriggerService
 * @uses \OCA\Dossiq\Service\Status\CaseStateFieldRuleProjector
 */
class CaseTypePublishValidationTest extends TestCase {

	/**
	 * The rows the resolver reads.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * A publish service over a register holding one publishable draft.
	 *
	 * @param array<string, mixed> $handling The handling block to put on the draft.
	 *
	 * @return CaseTypePublishService The service.
	 */
	private function service(array $handling): CaseTypePublishService {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'caseType',
			uuid: 'ct',
			row: [
				'id' => 'ct',
				'title' => 'Bezwaar',
				'isDraft' => true,
				'initialStatus' => 's1',
				'handling' => $handling,
			],
		);
		$this->register->seed(
			schema: 'statusType',
			uuid: 's1',
			row: ['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'ct'],
		);
		$this->register->seed(
			schema: 'statusType',
			uuid: 's2',
			row: ['id' => 's2', 'name' => 'Afgehandeld', 'caseType' => 'ct', 'isFinal' => true],
		);

		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				'status_type_schema' => 'statusType',
				'result_type_schema' => 'resultType',
				'role_type_schema' => 'roleType',
				'property_definition_schema' => 'propertyDefinition',
				'document_type_schema' => 'documentType',
				'decision_type_schema' => 'decisionType',
				default => $default,
			}
		);

		$store = new CaseTypeStore($settings);

		return new CaseTypePublishService(
			settingsService: $settings,
			caseTypeResolver: new CaseTypeResolver(store: $store),
			store: $store,
			acknowledgement: new CaseTypeAcknowledgement(),
			unreadTriggers: new UnreadTriggerService(),
			handling: new CaseTypeHandling(),
			fieldRules: new CaseStateFieldRuleProjector(
				store: $store,
				declaration: new StatusFieldRuleDeclaration(),
				roles: new FieldRoleRuleDeclaration(),
				slugs: $this->createMock(SchemaSlugResolver::class),
				container: $this->createMock(ContainerInterface::class),
				logger: new NullLogger(),
			),
			fieldRoles: $this->createMock(CaseFieldRoleProjector::class),
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * A switch nothing reads stops publication, and the refusal names it.
	 *
	 * @return void
	 */
	public function testASwitchNothingReadsCannotBePublished(): void {
		$findings = $this->service(['defaultGroup' => 'toezicht', 'escalateAfterDays' => 5])
			->validate(caseTypeId: 'ct');

		$named = array_filter(
			$findings,
			static fn (string $finding): bool => str_contains($finding, 'escalateAfterDays')
		);

		self::assertNotEmpty(actual: $named, message: 'the refusal did not name the switch');
	}//end testASwitchNothingReadsCannotBePublished()

	/**
	 * A block holding only switches that are read produces no finding of its own.
	 *
	 * The control: without it, a refusal that fired on every block would pass
	 * the test above and break every publish.
	 *
	 * @return void
	 */
	public function testABlockOfReadSwitchesProducesNoHandlingFinding(): void {
		$findings = $this->service(
			[
				'defaultGroup' => 'toezicht',
				'defaultHandler' => 'ahmed',
				'automaticMessages' => ['ontvangstbevestiging'],
				'intakeScreen' => 'vergunning-intake',
			]
		)->validate(caseTypeId: 'ct');

		$handlingFindings = array_filter(
			$findings,
			static fn (string $finding): bool => str_contains($finding, 'handling switch')
		);

		self::assertSame(expected: [], actual: $handlingFindings);
	}//end testABlockOfReadSwitchesProducesNoHandlingFinding()

	/**
	 * A case type carrying no block at all is not refused.
	 *
	 * Every case type on every existing instance is one of these, so a refusal
	 * here would make the whole fleet unpublishable on the day this shipped.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoBlockIsNotRefused(): void {
		$findings = $this->service([])->validate(caseTypeId: 'ct');

		$handlingFindings = array_filter(
			$findings,
			static fn (string $finding): bool => str_contains($finding, 'handling switch')
		);

		self::assertSame(expected: [], actual: $handlingFindings);
	}//end testACaseTypeWithNoBlockIsNotRefused()
}//end class
