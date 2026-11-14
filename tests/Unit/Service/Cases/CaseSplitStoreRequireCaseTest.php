<?php

/**
 * Reading the case a split starts from, and the refusal an unreadable one earns.
 *
 * Pins the three answers of CaseSplitStore::requireCase(): the stored case,
 * an indeterminate refusal when the register cannot be read, and an
 * unprocessable refusal when the case is not there.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\CaseSplitStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The case a split reads, or the refusal that stops it.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitStore
 * @uses   \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses   \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseSplitStoreRequireCaseTest extends TestCase {
	/**
	 * A case the register finds comes back as stored, read from the configured register and schema.
	 *
	 * @return void
	 */
	public function testAStoredCaseIsReturnedAsStored(): void {
		$service = new class {
			/**
			 * The calls the store made.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Find one object.
			 *
			 * @param string     $id       The uuid.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				$this->calls[] = ['id' => $id, 'register' => $register, 'schema' => $schema];

				return ['id' => $id, 'title' => 'Bouwvergunning'];
			}//end find()
		};

		$store = $this->store(service: $service, config: ['register' => 'dossiq', 'case_schema' => 'case']);

		self::assertSame(['id' => 'case-1', 'title' => 'Bouwvergunning'], $store->requireCase(caseId: 'case-1'));
		self::assertSame([['id' => 'case-1', 'register' => 'dossiq', 'schema' => 'case']], $service->calls);
	}//end testAStoredCaseIsReturnedAsStored()

	/**
	 * A register that cannot be read is an indeterminate refusal carrying the cause.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsAnIndeterminateRefusal(): void {
		$cause = new RuntimeException('database gone');
		$service = new class ($cause) {
			/**
			 * Constructor.
			 *
			 * @param RuntimeException $cause What find throws.
			 */
			public function __construct(private RuntimeException $cause) {
			}//end __construct()

			/**
			 * Find one object, and fail.
			 *
			 * @param string     $id       The uuid.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed> Never.
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				throw $this->cause;
			}//end find()
		};

		$store = $this->store(service: $service, config: ['register' => 'dossiq', 'case_schema' => 'case']);

		try {
			$store->requireCase(caseId: 'case-1');
			self::fail('An unreadable case must be refused.');
		} catch (RefusedException $refused) {
			self::assertSame(CaseSplitExecutor::CASE_UNREADABLE, $refused->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $refused->getStatus());
			self::assertSame('We could not read that case, so it was not split.', $refused->getSentence());
			self::assertSame($cause, $refused->getPrevious());
		}
	}//end testAnUnreadableRegisterIsAnIndeterminateRefusal()

	/**
	 * Without OpenRegister the read is refused as indeterminate before anything is looked up.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterIsAnIndeterminateRefusal(): void {
		$store = $this->store(service: null, config: ['register' => 'dossiq', 'case_schema' => 'case']);

		try {
			$store->requireCase(caseId: 'case-1');
			self::fail('A case without OpenRegister must be refused.');
		} catch (RefusedException $refused) {
			self::assertSame(CaseSplitExecutor::CASE_UNREADABLE, $refused->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $refused->getStatus());
			self::assertInstanceOf(RuntimeException::class, $refused->getPrevious());
			self::assertSame('OpenRegister is not available', $refused->getPrevious()->getMessage());
		}
	}//end testMissingOpenRegisterIsAnIndeterminateRefusal()

	/**
	 * A case the register does not hold is an unprocessable refusal.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsAnUnprocessableRefusal(): void {
		$service = new class {
			/**
			 * Find one object that is not there.
			 *
			 * @param string     $id       The uuid.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return null Nothing.
			 */
			public function find(string $id, int|string $register, int|string $schema): mixed {
				return null;
			}//end find()
		};

		$store = $this->store(service: $service, config: ['register' => 'dossiq', 'case_schema' => 'case']);

		try {
			$store->requireCase(caseId: 'case-gone');
			self::fail('A missing case must be refused.');
		} catch (RefusedException $refused) {
			self::assertSame(CaseSplitExecutor::CASE_UNREADABLE, $refused->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refused->getStatus());
			self::assertSame('We could not read that case, so it was not split.', $refused->getSentence());
			self::assertNull($refused->getPrevious());
		}
	}//end testAMissingCaseIsAnUnprocessableRefusal()

	/**
	 * The store over settings answering the given object service and configuration.
	 *
	 * @param object|null           $service The object service, or null.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return CaseSplitStore The store.
	 */
	private function store(?object $service, array $config): CaseSplitStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new CaseSplitStore(settingsService: $settings, ledger: $this->createMock(originalClassName: CaseMoveLedger::class));
	}//end store()
}//end class
