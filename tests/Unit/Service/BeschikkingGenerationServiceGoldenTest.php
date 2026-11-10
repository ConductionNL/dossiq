<?php

/**
 * BeschikkingGenerationService golden test
 *
 * Pins what generating a decision document reads, asks the document app
 * for, saves as an attachment, logs and answers, across the missing
 * template, document app and session, and the failures of each write.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BeschikkingGenerationService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for BeschikkingGenerationService::generateBeschikking().
 *
 * @covers \OCA\Dossiq\Service\BeschikkingGenerationService
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class BeschikkingGenerationServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every call any collaborator received.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * Run one generation against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The answers per collaborator and the call.
	 *
	 * @return array<string, mixed> The answer, the calls and the log lines.
	 */
	private function observe(array $world): array {
		$this->calls = [];
		$test = $this;
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

		$saves = ($world['saves'] ?? [['id' => 'bijlage-1']]);
		$objects = new class ($test, $saves) {
			/**
			 * How many saves came in.
			 *
			 * @var int
			 */
			private int $count = 0;

			/**
			 * Constructor.
			 *
			 * @param BeschikkingGenerationServiceGoldenTest $test  Keeps the calls.
			 * @param array<int, mixed>                      $saves The answer per save, or 'throw'.
			 */
			public function __construct(private BeschikkingGenerationServiceGoldenTest $test, private array $saves) {
			}

			/**
			 * Save one object.
			 *
			 * @param mixed $register The register.
			 * @param mixed $schema   The schema.
			 * @param mixed $object   The object.
			 *
			 * @return mixed The saved object.
			 */
			public function saveObject(mixed $object, ObjectSaveOptions $options = new ObjectSaveOptions()): mixed {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->test->calls[] = ['ObjectService::saveObject', ['register' => $register, 'schema' => $schema, 'object' => $object]];
				$answer = ($this->saves[$this->count] ?? null);
				$this->count++;
				if ($answer === 'throw') {
					throw new RuntimeException('save refused');
				}

				return $answer;
			}
		};

		$documents = null;
		if (($world['documents'] ?? 'yes') === 'yes') {
			$documents = new class ($test, $world) {
				/**
				 * Constructor.
				 *
				 * @param BeschikkingGenerationServiceGoldenTest $test  Keeps the calls.
				 * @param array<string, mixed>                   $world The world.
				 */
				public function __construct(private BeschikkingGenerationServiceGoldenTest $test, private array $world) {
				}

				/**
				 * Render a template.
				 *
				 * @param mixed $templateId The template.
				 * @param mixed $data       The data.
				 * @param mixed $options    The options.
				 *
				 * @return mixed The generated document.
				 */
				public function generateDocument(mixed $templateId, mixed $data, mixed $options): mixed {
					$this->test->calls[] = ['DocumentService::generateDocument', [$templateId, $data, $options]];
					if (($this->world['generated'] ?? null) === 'throw') {
						throw new RuntimeException('render failed');
					}

					return ($this->world['generated'] ?? ['output' => ['fileId' => 41, 'name' => 'besluit.pdf']]);
				}
			};
		} elseif ($world['documents'] === 'no-method') {
			$documents = new \stdClass();
		}

		$container = new class ($test, $documents, $objects, $world) implements ContainerInterface {
			/**
			 * Constructor.
			 *
			 * @param BeschikkingGenerationServiceGoldenTest $test      Keeps the calls.
			 * @param object|null                            $documents The document service, or null.
			 * @param object                                 $objects   The object service.
			 * @param array<string, mixed>                   $world     The world.
			 */
			public function __construct(private BeschikkingGenerationServiceGoldenTest $test, private ?object $documents, private object $objects, private array $world) {
			}

			/**
			 * Get one service.
			 *
			 * @param string $id The service id.
			 *
			 * @return mixed The service.
			 */
			public function get(string $id): mixed {
				$this->test->calls[] = ['Container::get', $id];
				if ($id === 'OCA\OpenRegister\Service\ObjectService' && ($this->world['objectService'] ?? true) === true) {
					return $this->objects;
				}

				if ($this->documents !== null && str_ends_with($id, '\Service\DocumentService') === true) {
					return $this->documents;
				}

				throw new RuntimeException('not registered: ' . $id);
			}

			/**
			 * Whether a service exists.
			 *
			 * @param string $id The service id.
			 *
			 * @return bool Always false; the service reads through get().
			 */
			public function has(string $id): bool {
				return false;
			}
		};

		$config = ($world['config'] ?? ['dso_beschikking_template_verleend' => 'tpl-ok', 'dso_beschikking_template_geweigerd' => 'tpl-no', 'register' => 'reg']);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config): string {
				$this->calls[] = ['IAppConfig::getValueString', [$app, $key, $default]];
				return (string)($config[$key] ?? $default);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$uid = 'anna';
		if (array_key_exists('user', $world) === true) {
			$uid = $world['user'];
		}

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$service = new BeschikkingGenerationService(appConfig: $appConfig, container: $container, userSession: $session, logger: $logger);
		$answer = $service->generateBeschikking(
			caseId: 'case-1',
			outcome: ($world['outcome'] ?? 'granted'),
			motivation: ($world['motivation'] ?? 'Past in het plan'),
		);

		$json = (string)json_encode(['answer' => $answer, 'calls' => $this->calls, 'logs' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION);
		$json = (string)preg_replace('/"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d"/', '"<now>"', $json);
		$json = (string)preg_replace('/"\d{4}-\d\d-\d\d"/', '"<today>"', $json);
		$json = (string)preg_replace('/class@anonymous[^"]*/', 'class@anonymous', $json);

		return json_decode($json, true);
	}//end observe()

	/**
	 * Every path through generateBeschikking().
	 *
	 * @return void
	 */
	public function testGoldenGenerate(): void {
		$worlds = [
			'granted' => [],
			'refused' => ['outcome' => 'refused'],
			'another outcome uses the granted template' => ['outcome' => 'withdrawn'],
			'no template configured' => ['config' => ['register' => 'reg']],
			'no document app' => ['documents' => 'none'],
			'document app without generateDocument' => ['documents' => 'no-method'],
			'no session' => ['user' => null],
			'empty uid' => ['user' => ''],
			'generated with top-level file' => ['generated' => ['fileId' => 7, 'fileName' => 'top.pdf']],
			'generated output not a list' => ['generated' => ['output' => 'files', 'fileId' => 8]],
			'generated nothing' => ['generated' => []],
			'generation fails' => ['generated' => 'throw'],
			'generation fails, refused' => ['generated' => 'throw', 'outcome' => 'refused'],
			'attachment saved by uuid' => ['saves' => [['uuid' => 'u-1']]],
			'attachment saved without id' => ['saves' => [[]]],
			'attachment save fails' => ['saves' => ['throw']],
			'stub saved by uuid' => ['user' => null, 'saves' => [['uuid' => 'u-2']]],
			'stub saved without id' => ['user' => null, 'saves' => [null]],
			'stub save fails' => ['user' => null, 'saves' => ['throw']],
			'generation fails, stub save fails' => ['generated' => 'throw', 'saves' => ['throw']],
			'no object service' => ['objectService' => false],
			'no object service, no session' => ['objectService' => false, 'user' => null],
			'register not configured' => ['config' => ['dso_beschikking_template_verleend' => 'tpl-ok']],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'decision-document-generation', value: $observed);
	}//end testGoldenGenerate()
}//end class
