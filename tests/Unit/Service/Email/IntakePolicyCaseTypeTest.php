<?php

/**
 * IntakePolicy case-type unit tests
 *
 * The intake policy a case type declares is read from OpenRegister: a
 * declared policy is used (case and spaces aside), and a case type that
 * cannot be read, does not exist, or declares something unknown gets the
 * default, quarantine. A failed read is logged at debug level only, because
 * intake runs on a cron and must keep emptying the mailbox.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for reading the intake policy off a case type.
 *
 * @covers \OCA\Dossiq\Service\Email\IntakePolicy
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class IntakePolicyCaseTypeTest extends TestCase {

	public function testTheCaseTypesDeclaredPolicyIsUsed(): void {
		$objects = $this->objects(['ct-bezwaar' => ['id' => 'ct-bezwaar', 'intakePolicy' => ' REFUSE ']]);

		$this->assertSame(IntakePolicy::REFUSE, $this->policy(objects: $objects)->forCaseType(caseTypeId: 'ct-bezwaar'));
		$this->assertSame([['ct-bezwaar', 'dossiq', 'caseType']], $objects->finds);
	}//end testTheCaseTypesDeclaredPolicyIsUsed()

	public function testACaseTypeEntityIsSerialisedFirst(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['intakePolicy' => 'accept'];
			}
		};

		$this->assertSame(IntakePolicy::ACCEPT, $this->policy(objects: $this->objects(['ct-1' => $entity]))->forCaseType(caseTypeId: 'ct-1'));
	}//end testACaseTypeEntityIsSerialisedFirst()

	public function testAMissingOrUnreadableCaseTypeQuarantines(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			$this->stringContains('could not be read'),
			['caseType' => 'ct-broken', 'error' => 'register offline']
		);
		$policy = $this->policy(objects: $this->objects(['ct-null' => null, 'ct-broken' => new RuntimeException('register offline')]), logger: $logger);

		$this->assertSame(IntakePolicy::QUARANTINE, $policy->forCaseType(caseTypeId: 'ct-gone'));
		$this->assertSame(IntakePolicy::QUARANTINE, $policy->forCaseType(caseTypeId: 'ct-null'));
		$this->assertSame(IntakePolicy::QUARANTINE, $policy->forCaseType(caseTypeId: 'ct-broken'));
	}//end testAMissingOrUnreadableCaseTypeQuarantines()

	public function testAnUnknownOrAbsentDeclarationQuarantines(): void {
		$policy = $this->policy(objects: $this->objects(['ct-odd' => ['intakePolicy' => 'shred'], 'ct-plain' => ['id' => 'ct-plain']]));

		$this->assertSame(IntakePolicy::QUARANTINE, $policy->forCaseType(caseTypeId: 'ct-odd'));
		$this->assertSame(IntakePolicy::QUARANTINE, $policy->forCaseType(caseTypeId: 'ct-plain'));
	}//end testAnUnknownOrAbsentDeclarationQuarantines()

	public function testNothingIsReadWithoutOpenRegisterOrACaseTypeSchema(): void {
		$objects = $this->objects(['ct-1' => ['intakePolicy' => 'accept']]);

		$this->assertSame(IntakePolicy::QUARANTINE, $this->policy(objects: $objects, schema: '')->forCaseType(caseTypeId: 'ct-1'));
		$this->assertSame(IntakePolicy::QUARANTINE, $this->policy(objects: null)->forCaseType(caseTypeId: 'ct-1'));
		$this->assertSame([], $objects->finds);
	}//end testNothingIsReadWithoutOpenRegisterOrACaseTypeSchema()

	/**
	 * A fake object service over case types by id; a Throwable value is thrown.
	 *
	 * @param array<string, mixed> $caseTypes The case types.
	 *
	 * @return object
	 */
	private function objects(array $caseTypes): object {
		return new class($caseTypes) {
			/** @var array<int, array<int, mixed>> */
			public array $finds = [];

			/**
			 * @param array<string, mixed> $caseTypes The case types.
			 */
			public function __construct(private array $caseTypes) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				$this->finds[] = [$id, $register, $schema];
				if (array_key_exists($id, $this->caseTypes) === false) {
					throw new DoesNotExistException('gone');
				}

				if ($this->caseTypes[$id] instanceof \Throwable) {
					throw $this->caseTypes[$id];
				}

				return $this->caseTypes[$id];
			}
		};
	}//end objects()

	/**
	 * The policy over a fake object service.
	 *
	 * @param object|null          $objects The object service, or null when OpenRegister is absent.
	 * @param string               $schema  The configured case-type schema.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return IntakePolicy
	 */
	private function policy(?object $objects, string $schema = 'caseType', ?LoggerInterface $logger = null): IntakePolicy {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => 'dossiq', 'case_type_schema' => $schema][$key] ?? '')
		);

		return new IntakePolicy(
			settingsService: $settings,
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end policy()
}//end class
