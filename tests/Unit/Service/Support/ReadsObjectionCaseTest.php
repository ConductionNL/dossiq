<?php

/**
 * ReadsObjectionCase — the case a stored objection belongs to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsObjectionCase;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The objection's case with and without OpenRegister or configuration.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsObjectionCase
 */
final class ReadsObjectionCaseTest extends TestCase {

	/**
	 * A class using the trait over a settings service and a canned objection.
	 *
	 * @param SettingsService                   $settingsService The settings.
	 * @param array<string, mixed>|null         $objection       The stored objection.
	 *
	 * @return object
	 */
	private function subject(SettingsService $settingsService, ?array $objection): object {
		return new class($settingsService, $objection) {
			use ReadsObjectionCase;

			/**
			 * The lookups the trait made, as [register, schema, id].
			 *
			 * @var array<int, array<int, string>>
			 */
			public array $lookups = [];

			/**
			 * Constructor.
			 *
			 * @param SettingsService           $settingsService The settings.
			 * @param array<string, mixed>|null $objection       The stored objection.
			 */
			public function __construct(private readonly SettingsService $settingsService, private readonly ?array $objection) {
			}

			/**
			 * The canned read.
			 *
			 * @param object $objectService Unused.
			 * @param string $register      The register.
			 * @param string $schema        The schema.
			 * @param string $id            The id.
			 *
			 * @return array<string, mixed>|null
			 */
			private function findObjectAsArray(object $objectService, string $register, string $schema, string $id): ?array {
				$this->lookups[] = [$register, $schema, $id];
				return $this->objection;
			}

			/**
			 * The trait's answer.
			 *
			 * @param string $objectionId The objection.
			 *
			 * @return string
			 */
			public function caseOf(string $objectionId): string {
				return $this->storedObjectionCase(objectionId: $objectionId);
			}
		};
	}//end subject()

	/**
	 * Settings answering an object service and the given register and schema.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $register      The register.
	 * @param string      $schema        The bezwaar schema.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, string $register, string $schema): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', $register], ['bezwaar_schema', '', $schema]]);
		return $settings;
	}//end settings()

	/**
	 * The stored case is answered from the configured register and schema.
	 *
	 * @return void
	 */
	public function testAnswersTheStoredCase(): void {
		$subject = $this->subject($this->settings(new stdClass(), 'reg', 'bezwaar'), ['case' => 'case-1']);

		self::assertSame('case-1', $subject->caseOf('obj-1'));
		self::assertSame([['reg', 'bezwaar', 'obj-1']], $subject->lookups);
	}//end testAnswersTheStoredCase()

	/**
	 * Without OpenRegister, configuration or a stored case the answer is ''.
	 *
	 * @return void
	 */
	public function testAnswersEmptyWhenUnavailable(): void {
		self::assertSame('', $this->subject($this->settings(null, 'reg', 'bezwaar'), ['case' => 'x'])->caseOf('o'));
		self::assertSame('', $this->subject($this->settings(new stdClass(), '', 'bezwaar'), ['case' => 'x'])->caseOf('o'));
		self::assertSame('', $this->subject($this->settings(new stdClass(), 'reg', ''), ['case' => 'x'])->caseOf('o'));
		self::assertSame('', $this->subject($this->settings(new stdClass(), 'reg', 'bezwaar'), null)->caseOf('o'));
	}//end testAnswersEmptyWhenUnavailable()
}//end class
