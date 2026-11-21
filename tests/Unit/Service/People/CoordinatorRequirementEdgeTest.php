<?php

/**
 * The coordinator requirement at its edges: unreadable or incomplete cases ask for nobody.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Dossiq\Tests\Support\LegacyObjectArgs;

/**
 * Tests for CoordinatorRequirement at its edges.
 *
 * @covers \OCA\Dossiq\Service\People\CoordinatorRequirement
 * @uses \OCA\Dossiq\Service\People\CaseSeats
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 */
class CoordinatorRequirementEdgeTest extends TestCase {

	/**
	 * The store cases are read from.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The case type resolver double.
	 *
	 * @var CaseTypeResolver&MockObject
	 */
	private CaseTypeResolver $resolver;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A store with one case without a type, and a resolver that declares the requirement.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-untyped', row: ['title' => 'Untyped', 'caseType' => '  ']);
		$this->resolver = $this->createMock(CaseTypeResolver::class);
		$this->resolver->method('effectiveCaseType')->willReturn([CoordinatorRequirement::DECLARATION => true]);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The requirement over a settings service handing out this object service.
	 *
	 * @param object|null $objectService The object service, or null when OpenRegister is absent.
	 *
	 * @return CoordinatorRequirement The service under test.
	 */
	private function requirement(?object $objectService): CoordinatorRequirement {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		return new CoordinatorRequirement(
			settingsService: $settings,
			caseTypes: $this->resolver,
			seats: new CaseSeats(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->logger,
		);
	}//end requirement()

	/**
	 * A blank case id never reaches the register.
	 *
	 * @return void
	 */
	public function testABlankCaseIdAsksForNobody(): void {
		$this->resolver->expects(self::never())->method('effectiveCaseType');
		$this->logger->expects(self::never())->method('warning');

		self::assertFalse($this->requirement(objectService: $this->store)->applies(caseId: '   '));
	}//end testABlankCaseIdAsksForNobody()

	/**
	 * A case that does not exist asks for nobody, and signing it is not refused.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseAsksForNobody(): void {
		$this->resolver->expects(self::never())->method('effectiveCaseType');
		$requirement = $this->requirement(objectService: $this->store);

		self::assertFalse($requirement->applies(caseId: 'case-missing'));
		$requirement->requireSeatFilled(caseId: 'case-missing');
		self::assertTrue(true, 'An unknown case passes the signing check without throwing.');
	}//end testAnUnknownCaseAsksForNobody()

	/**
	 * A case without a case type asks for nobody and does not ask the resolver.
	 *
	 * @return void
	 */
	public function testACaseWithoutACaseTypeAsksForNobody(): void {
		$this->resolver->expects(self::never())->method('effectiveCaseType');

		self::assertFalse($this->requirement(objectService: $this->store)->applies(caseId: 'case-untyped'));
	}//end testACaseWithoutACaseTypeAsksForNobody()

	/**
	 * An absent OpenRegister is logged with the case id and asks for nobody.
	 *
	 * @return void
	 */
	public function testAnAbsentOpenRegisterIsLoggedAndAsksForNobody(): void {
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq coordinator seat: the case could not be read',
			['caseId' => 'case-1', 'exception' => 'OpenRegister is not available'],
		);

		self::assertFalse($this->requirement(objectService: null)->applies(caseId: ' case-1 '));
	}//end testAnAbsentOpenRegisterIsLoggedAndAsksForNobody()

	/**
	 * A register that throws on read is logged and asks for nobody.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAsksForNobody(): void {
		$objects = new class {
			/**
			 * Always fails.
			 *
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return never
			 */
			public function find(mixed ...$arguments): never {
				$arguments = LegacyObjectArgs::flatten(args: $arguments);
				throw new RuntimeException('register down');
			}
		};

		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq coordinator seat: the case could not be read',
			['caseId' => 'case-1', 'exception' => 'register down'],
		);

		self::assertFalse($this->requirement(objectService: $objects)->applies(caseId: 'case-1'));
	}//end testAFailingReadIsLoggedAndAsksForNobody()

	/**
	 * Only a declaration of exactly true asks; a truthy string does not.
	 *
	 * @return void
	 */
	public function testOnlyABooleanTrueDeclarationAsks(): void {
		$this->store->seed(schema: 'case', uuid: 'case-typed', row: ['caseType' => 'ct-1']);
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->expects(self::once())->method('effectiveCaseType')->with('ct-1')
			->willReturn([CoordinatorRequirement::DECLARATION => 'true']);
		$this->resolver = $resolver;

		self::assertFalse($this->requirement(objectService: $this->store)->applies(caseId: 'case-typed'));
	}//end testOnlyABooleanTrueDeclarationAsks()
}//end class
