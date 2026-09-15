<?php

/**
 * The Awb answer is signed by the second seat, where the case type says so.
 *
 * Three answers, and the third is the one that keeps the product usable: a case
 * type that declares nothing never asks for a coordinator. A melding openbare
 * ruimte that insists on two people is a product nobody uses, and a check that
 * defaulted ON would have made every case type ask.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Whether the seat is asked for, and what a signing meets when it is empty.
 *
 * @covers \OCA\Dossiq\Service\People\CoordinatorRequirement
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CoordinatorRequiredBeforeSigningTest extends TestCase {

	/**
	 * The store the case and its type are read from.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One case type that asks for a coordinator and one that does not.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'roleType', uuid: 'rt-casemanager', row: ['name' => 'Casemanager', 'genericRole' => 'coordinator']);
		$this->store->seed(schema: 'case', uuid: 'case-bezwaar', row: ['title' => 'Bezwaar', 'caseType' => 'ct-bezwaar']);
		$this->store->seed(schema: 'case', uuid: 'case-melding', row: ['title' => 'Melding', 'caseType' => 'ct-melding']);
	}//end setUp()

	/**
	 * An empty seat refuses the signing, and names the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function testAnEmptySeatRefusesTheSigning(): void {
		$requirement = $this->requirement(declarations: ['ct-bezwaar' => true]);

		self::assertTrue($requirement->applies(caseId: 'case-bezwaar'));

		try {
			$requirement->requireSeatFilled(caseId: 'case-bezwaar');
			self::fail('A case type that asks for a coordinator must refuse a signing without one.');
		} catch (RefusedException $e) {
			self::assertSame(CoordinatorRequirement::RULE, $e->getRule(), 'ADR-050 puts the rule slug in `error`.');
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus(), 'The caller can satisfy this by naming somebody.');
			self::assertStringContainsString('coordinator', $e->getSentence());
		}
	}//end testAnEmptySeatRefusesTheSigning()

	/**
	 * With the seat filled it signs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function testWithTheSeatFilledItSigns(): void {
		$this->store->seed(schema: 'role', uuid: 'role-1', row: [
			'case' => 'case-bezwaar',
			'roleType' => 'rt-casemanager',
			'participant' => 'user:sofie',
		]);

		$this->requirement(declarations: ['ct-bezwaar' => true])->requireSeatFilled(caseId: 'case-bezwaar');

		self::assertTrue(true, 'A filled seat passes the check without throwing.');
	}//end testWithTheSeatFilledItSigns()

	/**
	 * A case type with no declaration asks for nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function testACaseTypeThatDeclaresNothingAsksForNobody(): void {
		$requirement = $this->requirement(declarations: ['ct-bezwaar' => true]);

		self::assertFalse($requirement->applies(caseId: 'case-melding'));

		$requirement->requireSeatFilled(caseId: 'case-melding');

		self::assertTrue(true, 'A melding with no coordinator signs, because its type never asked.');
	}//end testACaseTypeThatDeclaresNothingAsksForNobody()

	/**
	 * A case type that cannot be read asks for nobody either.
	 *
	 * Falling closed here would block every signing on an instance whose case
	 * types are briefly unreadable, which is a worse failure than the one this
	 * check is for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	public function testAnUnreadableCaseTypeDoesNotBlockEverySigning(): void {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willThrowException(new \RuntimeException('register down'));

		$requirement = new CoordinatorRequirement(
			settingsService: $this->settings(),
			caseTypes: $resolver,
			seats: $this->seats(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		self::assertFalse($requirement->applies(caseId: 'case-bezwaar'));
	}//end testAnUnreadableCaseTypeDoesNotBlockEverySigning()

	/**
	 * A requirement reader over case types with the given declarations.
	 *
	 * @param array<string, bool> $declarations Case type uuid to its declaration.
	 *
	 * @return CoordinatorRequirement The service under test.
	 */
	private function requirement(array $declarations): CoordinatorRequirement {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(
			static fn (string $caseTypeId): array => [
				'id' => $caseTypeId,
				CoordinatorRequirement::DECLARATION => ($declarations[$caseTypeId] ?? false),
			]
		);

		return new CoordinatorRequirement(
			settingsService: $this->settings(),
			caseTypes: $resolver,
			seats: $this->seats(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end requirement()

	/**
	 * The seats reader over the same store.
	 *
	 * @return CaseSeats The collaborator.
	 */
	private function seats(): CaseSeats {
		return new CaseSeats(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end seats()

	/**
	 * A settings service over the in-memory store.
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'role_schema' => 'role',
					'role_type_schema' => 'roleType',
					'case_type_schema' => 'caseType',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
