<?php

/**
 * Unit tests for SensitiveAnswerCheck: a sensitive question counts as answered
 * only when the stored case carries a non-blank value under that exact name,
 * read without the caller's scoping.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SensitiveAnswerCheck;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SensitiveAnswerCheck
 *
 * @uses \OCA\Dossiq\Service\Support\UnscopedObjectReader
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class SensitiveAnswerCheckTest extends TestCase {

	/**
	 * An object service that records its find calls and answers one case.
	 *
	 * @param array<string, mixed>|null $case The stored case, or null when it does not exist.
	 */
	private function objectService(?array $case): object {
		return new class($case) {
			/** @var list<array<string, mixed>> */
			public array $finds = [];

			public function __construct(private ?array $case) {
			}

			public function find(string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): ?array {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->case === null) {
					throw new DoesNotExistException('missing');
				}

				return $this->case;
			}
		};
	}//end objectService()

	private function settings(?object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', '4'], ['case_schema', '', '12']]);
		return $settings;
	}//end settings()

	public function testANonBlankValueUnderTheNameIsAnAnswer(): void {
		$service = $this->objectService(
			['sensitiveProperties' => [['name' => 'bsn', 'value' => ''], 'junk', ['name' => 'income', 'value' => ' 42000 ']]]
		);

		$this->assertTrue((new SensitiveAnswerCheck($this->settings($service)))->isAnswered('case-1', 'income'));
		$this->assertSame(
			[['id' => 'case-1', 'register' => '4', 'schema' => '12', 'rbac' => false, 'multitenancy' => false]],
			$service->finds
		);
	}//end testANonBlankValueUnderTheNameIsAnAnswer()

	public function testABlankOrAbsentValueIsNoAnswer(): void {
		$service = $this->objectService(['sensitiveProperties' => [['name' => 'bsn', 'value' => '   '], ['name' => 'Income', 'value' => 'x']]]);
		$check = new SensitiveAnswerCheck($this->settings($service));

		$this->assertFalse($check->isAnswered('case-1', 'bsn'));
		$this->assertFalse($check->isAnswered('case-1', 'income'));
		$this->assertFalse($check->isAnswered('case-1', 'nationality'));
	}//end testABlankOrAbsentValueIsNoAnswer()

	public function testACaseWithoutSensitivePropertiesOrThatDoesNotExistIsNoAnswer(): void {
		$this->assertFalse((new SensitiveAnswerCheck($this->settings($this->objectService(['id' => 'case-1']))))->isAnswered('case-1', 'bsn'));
		$this->assertFalse((new SensitiveAnswerCheck($this->settings($this->objectService(null))))->isAnswered('case-1', 'bsn'));
	}//end testACaseWithoutSensitivePropertiesOrThatDoesNotExistIsNoAnswer()

	public function testNothingIsReadWithoutACaseANameOrAnObjectService(): void {
		$service = $this->objectService(['sensitiveProperties' => [['name' => 'bsn', 'value' => '1']]]);
		$check = new SensitiveAnswerCheck($this->settings($service));

		$this->assertFalse($check->isAnswered('', 'bsn'));
		$this->assertFalse($check->isAnswered('case-1', ''));
		$this->assertSame([], $service->finds);
		$this->assertFalse((new SensitiveAnswerCheck($this->settings(null)))->isAnswered('case-1', 'bsn'));
	}//end testNothingIsReadWithoutACaseANameOrAnObjectService()
}//end class
