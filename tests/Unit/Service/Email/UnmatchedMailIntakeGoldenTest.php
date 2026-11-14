<?php

/**
 * UnmatchedMailIntake golden test
 *
 * Pins what filing an unmatched mail as a case reads, saves, logs and
 * answers, across the configuration, the fallback case type's assignee, the
 * forms a mail's subject, sender and date take, and the store's answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden test for UnmatchedMailIntake::caseFor().
 *
 * @covers \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses \OCA\Dossiq\Service\Zaakdossier\DocumentListingLabels
 */
class UnmatchedMailIntakeGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call a collaborator received, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * File one mail against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator and the message.
	 *
	 * @return array<string, mixed> The answer, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$logger = new class extends AbstractLogger {
			/**
			 * The lines logged.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};

		$objects = new class($this, $world) {
			/**
			 * Constructor.
			 *
			 * @param UnmatchedMailIntakeGoldenTest $test  Where calls are recorded.
			 * @param array<string, mixed>          $world The answers.
			 */
			public function __construct(private UnmatchedMailIntakeGoldenTest $test, private array $world) {
			}//end __construct()

			/**
			 * OpenRegister's find(): throws for an object that does not exist.
			 *
			 * @param string|int $id       The id.
			 * @param mixed      $register The register.
			 * @param mixed      $schema   The schema.
			 *
			 * @return mixed The object.
			 */
			public function find(string|int $id, mixed $register = null, mixed $schema = null): mixed {
				$this->test->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				if (array_key_exists('caseType', $this->world) === false) {
					throw new DoesNotExistException('Object not found');
				}

				return $this->world['caseType'];
			}//end find()

			/**
			 * OpenRegister's saveObject().
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return mixed The saved object.
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null): mixed {
				$this->test->calls[] = ['saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema]];
				if (($this->world['save'] ?? null) === 'throws') {
					throw new RuntimeException('the store refused it');
				}

				if (array_key_exists('saved', $this->world) === true) {
					return $this->world['saved'];
				}

				return ['id' => 'case-9'] + $object;
			}//end saveObject()
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			function () use ($world, $objects): ?object {
				$this->calls[] = ['getObjectService', []];
				if (($world['objectService'] ?? true) === false) {
					return null;
				}

				return $objects;
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = '') use ($world): string {
				$this->calls[] = ['getConfigValue', ['key' => $key, 'default' => $default]];
				$config = (($world['config'] ?? []) + ['register' => 'reg-1', 'case_schema' => 'case', 'case_type_schema' => 'case-type']);

				return ($config[$key] ?? $default);
			}
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($world): string {
				$this->calls[] = ['getValueString', ['app' => $app, 'key' => $key, 'default' => $default]];

				return ($world['fallback'] ?? ' ct-mail ');
			}
		);

		$intake = new UnmatchedMailIntake(
			settingsService: $settings,
			assignees: new AssigneeResolver(logger: new NullLogger()),
			appConfig: $appConfig,
			logger: $logger,
		);
		$message = ($world['message'] ?? [
			'subject' => ' Vraag over mijn aanvraag ',
			'from' => 'Anna de Vries <ANNA@Example.nl>',
			'sentAt' => '2026-11-02T09:00:00+01:00',
		]);

		return ['answer' => $intake->caseFor(message: $message), 'calls' => $this->calls, 'logs' => $logger->lines];
	}//end observe()

	/**
	 * Every path through caseFor(), across its configuration, its message and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenCaseFor(): void {
		$entity = new class {
			/**
			 * The saved case, as an entity serialises it.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'case-uuid-7'];
			}//end jsonSerialize()
		};
		$worlds = [
			'filed' => ['caseType' => ['defaultAssignee' => 'bob']],
			'no fallback case type' => ['fallback' => ''],
			'blank fallback case type' => ['fallback' => '   '],
			'no object service' => ['objectService' => false],
			'no register' => ['config' => ['register' => '']],
			'no case schema' => ['config' => ['case_schema' => '']],
			'no case type schema' => ['config' => ['case_type_schema' => '']],
			'case type does not exist' => [],
			'case type names a handler block' => ['caseType' => ['defaultAssignee' => 'bob', 'handling' => ['defaultHandler' => 'carol']]],
			'case type names nobody' => ['caseType' => ['defaultAssignee' => 'none']],
			'case type read as an entity' => ['caseType' => $entity],
			'no subject' => ['message' => ['from' => 'anna@example.nl']],
			'long subject' => ['message' => ['subject' => str_repeat('ab', 140), 'from' => 'anna@example.nl']],
			'sender without a name' => ['message' => ['subject' => 'Vraag', 'from' => ' Anna@Example.NL ']],
			'sender not an address' => ['message' => ['subject' => 'Vraag', 'from' => 'Anna de Vries']],
			'sender with a broken address' => ['message' => ['subject' => 'Vraag', 'from' => 'Anna <not an address>']],
			'no sender and no date' => ['message' => ['subject' => 'Vraag']],
			'date only' => ['message' => ['subject' => 'Vraag', 'sentAt' => ' 2026-11-02 ']],
			'empty message' => ['message' => []],
			'save fails' => ['save' => 'throws'],
			'saved without an id' => ['saved' => ['title' => 'x']],
			'saved as nothing' => ['saved' => null],
			'saved as an entity' => ['saved' => $entity],
			'saved with an id object' => ['saved' => ['id' => ['id' => ' case-obj ']]],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'unmatched-mail-intake', value: $observed);
	}//end testGoldenCaseFor()
}//end class
