<?php

/**
 * AcknowledgementService golden test
 *
 * Pins what confirming receipt of a case reads, sends, writes, logs and
 * answers, across the case read, the duty guards, the address fallbacks,
 * the transport's answers and the e-mail copy.
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
 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\AcknowledgementService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for AcknowledgementService::acknowledge().
 *
 * @covers \OCA\Dossiq\Service\AcknowledgementService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Exception\NoticeNotSentException
 */
class AcknowledgementServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any collaborator received, by parameter name.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * A double whose listed methods record their call and answer as configured.
	 *
	 * Arguments left at their declared default are not recorded.
	 *
	 * @param string               $class     The collaborator class.
	 * @param array<string, mixed> $behaviour Per method: a value, or a closure over the named args.
	 *
	 * @return object The double.
	 */
	private function double(string $class, array $behaviour): object {
		$mock = $this->createMock($class);
		foreach ($behaviour as $method => $answer) {
			$params = (new ReflectionMethod($class, $method))->getParameters();
			$mock->method($method)->willReturnCallback(
				function (mixed ...$args) use ($class, $method, $params, $answer): mixed {
					$named = [];
					foreach ($args as $index => $value) {
						$param = $params[$index];
						if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $value) {
							continue;
						}

						$named[$param->getName()] = (is_object($value) === true ? 'object' : $value);
					}

					$short = substr($class, (strrpos($class, '\\') + 1));
					$this->calls[] = [$short . '::' . $method, $named];
					if ($answer instanceof \Closure) {
						return $answer(...$named);
					}

					return $answer;
				}
			);
		}

		return $mock;
	}//end double()

	/**
	 * Run one acknowledgement against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator and the call.
	 *
	 * @return array<string, mixed> The answer or exception, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$store = new class ($world, $this) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>             $world The world.
			 * @param AcknowledgementServiceGoldenTest $test  The test, which keeps the calls.
			 */
			public function __construct(private array $world, private AcknowledgementServiceGoldenTest $test) {
			}

			/**
			 * Read one object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed The stored row.
			 */
			public function find(string $id, string $register = '', string $schema = ''): mixed {
				$this->test->note(call: ['ObjectService::find', ['id' => $id, 'register' => $register, 'schema' => $schema]]);
				$found = ($this->world['find'] ?? 'missing');
				if ($found === 'missing') {
					throw new DoesNotExistException('Object not found');
				}

				if ($found === 'throw') {
					throw new RuntimeException('register down');
				}

				return $found;
			}
		};

		$configCalls = 0;
		$config = ($world['config'] ?? ['register' => 'reg', 'case_schema' => 'case']);
		$configLater = ($world['configLater'] ?? $config);
		$settings = $this->double(
			SettingsService::class,
			[
				'getObjectService' => (($world['objectService'] ?? true) === true ? $store : null),
				'getConfigValue' => static function (string $key) use (&$configCalls, $config, $configLater): string {
					$configCalls++;
					$values = $config;
					if ($configCalls > 2) {
						$values = $configLater;
					}

					return (string)($values[$key] ?? '');
				},
			]
		);

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

		$sends = ($world['sends'] ?? [['dispatch' => ['channel' => 'portal', 'sentAt' => '2026-11-01T10:00:00+00:00', 'record' => ['id' => 'rec-1']]]]);
		$sendCount = 0;
		$send = static function (mixed ...$named) use (&$sendCount, $sends): array {
			$answer = ($sends[$sendCount] ?? ($sends[(count($sends) - 1)] ?? []));
			$sendCount++;
			if (is_array($answer) === true && isset($answer['notSent']) === true) {
				throw new NoticeNotSentException(reasonCode: $answer['notSent'], reason: (string)($answer['reason'] ?? ''), delivery: (array)($answer['delivery'] ?? []));
			}

			return $answer;
		};

		$caseType = ($world['caseType'] ?? ['id' => 'ct-1', 'identifier' => 'omgeving', 'responsible' => 'Team Vergunningen']);
		$service = new AcknowledgementService(
			settingsService: $settings,
			caseTypeResolver: $this->double(CaseTypeResolver::class, ['effectiveCaseType' => $caseType]),
			store: $this->double(CaseTypeStore::class, ['referenceId' => static fn (mixed $value): string => (is_string($value) === true ? $value : '')]),
			declaration: $this->double(
				CaseTypeAcknowledgement::class,
				[
					'owesAcknowledgement' => ($world['owes'] ?? true),
					'channelFor' => ($world['channel'] ?? 'portal'),
					'contentStaysOnPlatform' => ($world['withheld'] ?? false),
					'addressesOn' => ($world['declared'] ?? []),
					'languageFor' => 'nl',
					'sendsEmailCopy' => ($world['copy'] ?? false),
				]
			),
			termService: $this->double(TermijnService::class, ['getTermijnInstanceForZaak' => ($world['term'] ?? null)]),
			notifications: $this->double(TermijnNotificationService::class, ['sendTermijnNotification' => $send]),
			contacts: $this->double(CaseContactDirectory::class, ['collectAddresses' => ($world['contacts'] ?? [])]),
			portal: $this->double(PortalContributionProvider::class, ['citizenCaseFields' => ['identifier', 'title', 'deadline', 'receivedAt', 'termStartsAt', 'receivedOutsideWorkingHours']]),
			writer: $this->double(
				CaseFieldWriter::class,
				[
					'write' => static function (mixed ...$named) use ($world): void {
						if (($world['writeFails'] ?? false) === true) {
							throw new RuntimeException('write refused');
						}
					},
				]
			),
			logger: $logger,
			timeline: $this->double(CaseTimeline::class, ['record' => 'tl-1']),
		);

		try {
			$answer = $service->acknowledge(caseId: ($world['caseId'] ?? 'case-1'), attempt: ($world['attempt'] ?? 1));
		} catch (\Throwable $thrown) {
			$answer = [
				'thrown' => get_class($thrown),
				'message' => $thrown->getMessage(),
				'code' => $thrown->getCode(),
				'previous' => ($thrown->getPrevious()?->getMessage()),
			];
			if ($thrown instanceof RefusedException) {
				$answer['rule'] = $thrown->getRule();
				$answer['status'] = $thrown->getStatus();
				$answer['sentence'] = $thrown->getSentence();
			}
		}

		return json_decode((string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION), true);
	}//end observe()

	/**
	 * Keep one call made by a hand-written double.
	 *
	 * @param array<int, mixed> $call The method and its named arguments.
	 *
	 * @return void
	 */
	public function note(array $call): void {
		$this->calls[] = $call;
	}//end note()

	/**
	 * Every path through acknowledge(), across its guards and its collaborators' answers.
	 *
	 * @return void
	 */
	public function testGoldenAcknowledge(): void {
		$case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'identifier' => 'Z-2026-1',
			'title' => 'Dakkapel',
			'deadline' => '2026-12-01',
			'receivedAt' => '2026-11-01T09:00:00+00:00',
			'termStartsAt' => '2026-11-02',
			'receivedOutsideWorkingHours' => true,
			'secret' => 'not quotable',
			'@self' => ['status' => 'published'],
		];
		$minimal = ['id' => 'case-1'];
		$worlds = [
			'no object service' => ['objectService' => false],
			'no register configured' => ['config' => ['case_schema' => 'case']],
			'no schema configured' => ['config' => ['register' => 'reg']],
			'empty case id' => ['caseId' => '', 'find' => $case],
			'case missing' => ['find' => 'missing'],
			'register throws' => ['find' => 'throw'],
			'draft' => ['find' => ['@self' => ['status' => 'draft'], 'acknowledgementDuty' => ['status' => 'pending']] + $case],
			'draft without duty' => ['find' => ['@self' => ['status' => 'draft']] + $case],
			'self not an array' => ['find' => ['@self' => 'draft'] + $case, 'contacts' => ['a@example.nl']],
			'not owed' => ['find' => $case, 'owes' => false],
			'not owed, write fails' => ['find' => $case, 'owes' => false, 'writeFails' => true],
			'already met' => ['find' => ['acknowledgementDuty' => ['status' => 'met', 'channel' => 'email']] + $case],
			'duty not an array' => ['find' => ['acknowledgementDuty' => 'met'] + $case, 'contacts' => ['a@example.nl']],
			'declared address first' => ['find' => $case, 'declared' => ['decl@example.nl'], 'contacts' => ['c@example.nl']],
			'contact address' => ['find' => $case, 'contacts' => ['c@example.nl', 'd@example.nl']],
			'initiator e-mail lowercased' => ['find' => ['initiatorSourceId' => ' Burger@Example.NL '] + $case],
			'initiator not an e-mail' => ['find' => ['initiatorSourceId' => 'bsn-123', 'portalSubject' => ' subj-1 '] + $case],
			'no address at all' => ['find' => $case],
			'term by id' => ['find' => $case, 'term' => ['id' => 't-1', 'uuid' => 't-u', 'endDateCurrent' => '2026-12-15'], 'contacts' => ['c@example.nl']],
			'term by uuid' => ['find' => $case, 'term' => ['uuid' => 't-u'], 'contacts' => ['c@example.nl']],
			'minimal case' => ['find' => $minimal, 'contacts' => ['c@example.nl']],
			'case type unreferenced' => ['find' => ['caseType' => ['nested']] + $case, 'contacts' => ['c@example.nl']],
			'withheld, attempt 3' => ['find' => $case, 'withheld' => true, 'attempt' => 3, 'channel' => 'digital-post', 'contacts' => ['c@example.nl']],
			'sent, no copy declared' => ['find' => $case, 'contacts' => ['c@example.nl']],
			'sent by portal, copy declared' => [
				'find' => $case,
				'copy' => true,
				'contacts' => ['c@example.nl'],
				'sends' => [
					['dispatch' => ['channel' => 'portal', 'sentAt' => 's1', 'record' => ['id' => 'r1']]],
					['dispatch' => ['channel' => 'email', 'sentAt' => 's2']],
				],
			],
			'sent by e-mail, copy declared' => ['find' => $case, 'copy' => true, 'contacts' => ['c@example.nl'], 'sends' => [['dispatch' => ['channel' => 'email', 'sentAt' => 's1']]]],
			'copy not sent' => [
				'find' => $case,
				'copy' => true,
				'contacts' => ['c@example.nl'],
				'sends' => [['dispatch' => ['channel' => 'portal', 'sentAt' => 's1']], ['notSent' => 'transport-failed', 'reason' => 'smtp down']],
			],
			'no dispatch in payload, copy declared' => ['find' => $case, 'copy' => true, 'contacts' => ['c@example.nl'], 'sends' => [['other' => 1], ['dispatch' => []]]],
			'dispatch not an array' => ['find' => $case, 'contacts' => ['c@example.nl'], 'sends' => [['dispatch' => 'x']]],
			'met but write fails' => ['find' => $case, 'contacts' => ['c@example.nl'], 'writeFails' => true],
			'met but register gone before write' => ['find' => $case, 'contacts' => ['c@example.nl'], 'configLater' => ['register' => '', 'case_schema' => 'case']],
			'not sent: no channel' => ['find' => $case, 'sends' => [['notSent' => 'no-channel', 'delivery' => ['channelsTried' => []]]]],
			'not sent: transport' => ['find' => $case, 'contacts' => ['c@example.nl'], 'sends' => [['notSent' => 'transport-failed', 'reason' => 'all transports refused', 'delivery' => ['channelsTried' => ['portal', 'email']]]]],
			'not sent: default reason' => ['find' => $case, 'contacts' => ['c@example.nl'], 'sends' => [['notSent' => 'other']]],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'acknowledgement-service', value: $observed);
	}//end testGoldenAcknowledge()
}//end class
