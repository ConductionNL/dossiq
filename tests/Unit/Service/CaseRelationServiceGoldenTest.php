<?php

/**
 * CaseRelationService golden test
 *
 * Pins what listing, adding, removing, cleaning up and normalising relations
 * read, write, log and answer, against an in-memory register that records
 * every call.
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
 * @spec openspec/specs/related-case-linking/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationLabels;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Relation\SubjectLinkRule;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCP\IL10N;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden test for CaseRelationService.
 *
 * @covers \OCA\Dossiq\Service\CaseRelationService
 * @uses \OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationLabels
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationStore
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Notice\CaseActNotices
 * @uses \OCA\Dossiq\Service\Relation\RelationLabelRun
 * @uses \OCA\Dossiq\Service\Relation\SubjectLinkRule
 * @uses \OCA\Dossiq\Service\Cases\CaseMergeStore
 * @uses \OCA\Dossiq\Service\Support\ReferenceId
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseRelationServiceGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * A register over these cases that records every call.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases by id.
	 * @param array<string, mixed>                $knobs findThrows (ids), saveThrows (ids), usedThrows (bool).
	 *
	 * @return object The register.
	 */
	private static function register(array $cases, array $knobs): object {
		return new class($cases, $knobs) {
			/**
			 * Every call, in order.
			 *
			 * @var array<int, mixed>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $cases The cases.
			 * @param array<string, mixed>                $knobs The failures.
			 */
			public function __construct(public array $cases, private array $knobs) {
			}

			/**
			 * Record the register.
			 *
			 * @param mixed $register The register.
			 *
			 * @return void
			 */
			public function setRegister($register): void {
				$this->calls[] = ['setRegister', $register];
			}

			/**
			 * Record the schema.
			 *
			 * @param mixed $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema($schema): void {
				$this->calls[] = ['setSchema', $schema];
			}

			/**
			 * Find one case.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The case.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$this->calls[] = ['find', $id, $register, $schema];
				if (in_array($id, ($this->knobs['findThrows'] ?? []), true) === true) {
					throw new RuntimeException('find failed on ' . $id);
				}

				return ($this->cases[$id] ?? null);
			}

			/**
			 * Save one case.
			 *
			 * @param array<string, mixed> $object   The case.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed> The case.
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$this->calls[] = ['save', $object, $register, $schema];
				$id = (string)($object['id'] ?? '');
				if (in_array($id, ($this->knobs['saveThrows'] ?? []), true) === true) {
					throw new RuntimeException('save failed on ' . $id);
				}

				if ($id !== '') {
					$this->cases[$id] = $object;
				}

				return $object;
			}

			/**
			 * The cases this one points at.
			 *
			 * @param string $objectId The case.
			 *
			 * @return array<string, mixed> The rows.
			 */
			public function getObjectUses(string $objectId): array {
				$this->calls[] = ['uses', $objectId];
				$results = [];
				foreach (CaseRelationCodec::TYPED_PROPERTIES as $type => $property) {
					foreach ((array)($this->cases[$objectId][$property] ?? []) as $uuid) {
						if (isset($this->cases[(string)$uuid]) === true) {
							$results[] = $this->cases[(string)$uuid] + ['relation' => ['property' => $property, 'type' => $type, 'direction' => 'outgoing', 'label' => 'near-' . $type, 'inverseLabel' => 'far-' . $type, 'displayLabel' => 'near-' . $type]];
						}
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}

			/**
			 * The cases pointing at this one.
			 *
			 * @param string $objectId The case.
			 *
			 * @return array<string, mixed> The rows.
			 */
			public function getObjectUsedBy(string $objectId): array {
				$this->calls[] = ['usedBy', $objectId];
				if (($this->knobs['usedThrows'] ?? false) === true) {
					throw new RuntimeException('used failed');
				}

				$results = [];
				foreach ($this->cases as $candidate) {
					foreach (CaseRelationCodec::TYPED_PROPERTIES as $type => $property) {
						if (in_array($objectId, (array)($candidate[$property] ?? []), true) === true) {
							$results[] = $candidate + ['relation' => ['property' => $property, 'type' => $type, 'direction' => 'incoming', 'label' => 'near-' . $type, 'inverseLabel' => 'far-' . $type, 'displayLabel' => 'far-' . $type]];
						}
					}
				}

				return ['results' => $results, 'total' => count($results)];
			}
		};
	}//end register()

	/**
	 * The cases every world starts from.
	 *
	 * @return array<string, array<string, mixed>> The cases.
	 */
	private static function cases(): array {
		return [
			'a' => ['id' => 'a', 'title' => ' Bezwaar '],
			'b' => ['id' => 'b', 'title' => 'Besluit'],
			'c' => ['id' => 'c', 'title' => 'Derde'],
			'p' => ['id' => 'p', 'title' => 'Hoofdzaak'],
			'kid' => ['id' => 'kid', 'title' => 'Deelzaak', 'parentCase' => 'p'],
		];
	}//end cases()

	/**
	 * Run a sequence of calls against a world and record every effect.
	 *
	 * @param array<string, mixed> $world The seed, knobs, config and the calls.
	 *
	 * @return array<string, mixed> The answers, calls, logs and the cases after.
	 */
	private function observe(array $world): array {
		$cases = array_merge(self::cases(), ($world['seed'] ?? []));
		$register = self::register(cases: $cases, knobs: ($world['knobs'] ?? []));
		$map = ($world['config'] ?? []) + ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($world['noService'] ?? false) === true ? null : $register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (string)($map[$key] ?? $default)
		);

		$logger = new class extends AbstractLogger {
			/**
			 * Every log line.
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

		$store = new CaseRelationStore($settings, $logger);
		$codec = new CaseRelationCodec();
		$service = new CaseRelationService(
			store: $store,
			codec: $codec,
			hierarchyGuard: new CaseHierarchyOverlapGuard(),
			labels: new CaseRelationLabels(store: $store, codec: $codec),
			subjectRule: new SubjectLinkRule(store: $store, codec: $codec, notices: new CaseActNotices(l10n: self::untranslated())),
		);

		$answers = [];
		foreach ($world['calls'] as $call) {
			$answers[] = $call($service);
		}

		return json_decode(
			(string)json_encode(
				['answers' => $answers, 'calls' => $register->calls, 'logs' => $logger->lines, 'cases' => $register->cases],
				JSON_PRESERVE_ZERO_FRACTION
			),
			true
		);
	}//end observe()

	/**
	 * Every operation across its guards, its relation types and its failures.
	 *
	 * @return void
	 */
	public function testGoldenCaseRelationService(): void {
		$add = static fn (string $from, string $to, string $type, ?string $notes = null): \Closure => static fn (CaseRelationService $s): array => $s->addRelation(caseId: $from, targetId: $to, natureRelationship: $type, notes: $notes);
		$remove = static fn (string $from, string $to, string $type): \Closure => static fn (CaseRelationService $s): array => $s->removeRelation(caseId: $from, targetId: $to, natureRelationship: $type);
		$list = static fn (string $id): \Closure => static fn (CaseRelationService $s): array => $s->listRelations(caseId: $id);
		$cleanup = static fn (string $id): \Closure => static fn (CaseRelationService $s): int => $s->cleanupForDeletedCase(caseId: $id);
		$normalise = static fn (string $id): \Closure => static function (CaseRelationService $s) use ($id): string {
			$s->normalise(caseId: $id);
			return 'done';
		};
		$legacy = static fn (array $entries): string => (string)json_encode($entries);

		$worlds = [];
		foreach (CaseRelationService::RELATION_TYPES as $type) {
			$worlds['add ' . $type . ', then list both sides'] = ['calls' => [$add('a', 'b', $type, 'n-' . $type), $list('a'), $list('b')]];
			$worlds['add ' . $type . ' twice, then reversed'] = ['calls' => [$add('a', 'b', $type), $add('a', 'b', $type), $add('b', 'a', $type)]];
			$worlds['add and remove ' . $type] = ['calls' => [$add('a', 'b', $type), $remove('a', 'b', $type), $list('a'), $list('b')]];
		}

		$worlds += [
			'add, unknown type' => ['calls' => [$add('a', 'b', 'friend')]],
			'add, no origin id' => ['calls' => [$add('', 'b', 'subject')]],
			'add, no target id' => ['calls' => [$add('a', '', 'subject')]],
			'add, to itself' => ['calls' => [$add('a', 'a', 'subject')]],
			'add, origin missing' => ['calls' => [$add('x', 'b', 'subject')]],
			'add, target missing' => ['calls' => [$add('a', 'x', 'subject')]],
			'add, target unreadable' => ['knobs' => ['findThrows' => ['b']], 'calls' => [$add('a', 'b', 'subject')]],
			'add, parent to its sub-case' => ['calls' => [$add('p', 'kid', 'samenhang')]],
			'add, sub-case to its parent' => ['calls' => [$add('kid', 'p', 'vervolg')]],
			'add, parent as an object' => ['seed' => ['kid' => ['id' => 'kid', 'parentCase' => ['id' => 'p']]], 'calls' => [$add('p', 'kid', 'samenhang')]],
			'add, a second type on the same pair' => ['calls' => [$add('a', 'b', 'subject'), $add('a', 'b', 'vervolg'), $add('a', 'c', 'subject'), $list('a')]],
			'add, typed link already held by the target' => ['seed' => ['b' => ['id' => 'b', 'subjectCases' => ['a']]], 'calls' => [$add('a', 'b', 'subject')]],
			'add, legacy pair already held by the origin' => ['seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject']])]], 'calls' => [$add('a', 'b', 'subject')]],
			'add, keeps the earlier entries' => ['seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'c', 'aardRelatie' => 'bijdrage', 'notes' => 'oud']]), 'followUpCases' => ['c']]], 'calls' => [$add('a', 'b', 'vervolg', 'nieuw')]],
			'add, save fails' => ['knobs' => ['saveThrows' => ['a']], 'calls' => [$add('a', 'b', 'subject')]],
			'add, no case schema' => ['config' => ['case_schema' => ''], 'calls' => [$add('a', 'b', 'subject')]],
			'add, no object service' => ['noService' => true, 'calls' => [$add('a', 'b', 'subject')]],
			'list, missing case' => ['calls' => [$list('x')]],
			'list, legacy entries only' => [
				'seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject', 'notes' => 'x'], ['caseId' => '', 'aardRelatie' => 'subject'], ['caseId' => 'gone', 'aardRelatie' => 'vervolg'], ['caseId' => 'c']])]],
				'calls' => [$list('a')],
			],
			'list, legacy entry already typed' => [
				'seed' => ['a' => ['id' => 'a', 'subjectCases' => ['b'], 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject'], ['caseId' => 'b', 'aardRelatie' => 'vervolg']])]],
				'calls' => [$list('a'), $list('b')],
			],
			'list, relation rows unreadable' => ['knobs' => ['usedThrows' => true], 'seed' => ['b' => ['id' => 'b', 'subjectCases' => ['a']]], 'calls' => [$list('a')]],
			'remove, no ids' => ['calls' => [$remove('', 'b', 'subject'), $remove('a', '', 'subject')]],
			'remove, missing case' => ['calls' => [$remove('a', 'x', 'subject'), $remove('x', 'a', 'subject')]],
			'remove, both sides held it' => [
				'seed' => [
					'a' => ['id' => 'a', 'subjectCases' => ['b', 'c'], 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject'], ['caseId' => 'c', 'aardRelatie' => 'subject']])],
					'b' => ['id' => 'b', 'subjectCases' => ['a'], 'relatedCases' => $legacy([['caseId' => 'a', 'aardRelatie' => 'subject']])],
				],
				'calls' => [$remove('a', 'b', 'subject')],
			],
			'remove, a type that was not there' => ['calls' => [$add('a', 'b', 'subject'), $remove('a', 'b', 'vervolg')]],
			'remove, the first save fails' => ['knobs' => ['saveThrows' => ['a']], 'seed' => ['a' => ['id' => 'a', 'subjectCases' => ['b']]], 'calls' => [$remove('a', 'b', 'subject')]],
			'cleanup, no id' => ['calls' => [$cleanup('')]],
			'cleanup, the case still there' => [
				'seed' => [
					'a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject'], ['caseId' => 'c', 'aardRelatie' => 'vervolg'], ['caseId' => 'gone', 'aardRelatie' => 'vervolg'], ['caseId' => 'a', 'aardRelatie' => 'samenhang']])],
					'b' => ['id' => 'b', 'relatedCases' => $legacy([['caseId' => 'a', 'aardRelatie' => 'subject'], ['caseId' => 'c', 'aardRelatie' => 'subject']])],
					'c' => ['id' => 'c', 'followUpCases' => ['a', 'b']],
				],
				'calls' => [$cleanup('a')],
			],
			'cleanup, the case already gone' => [
				'seed' => [
					'b' => ['id' => 'b', 'subjectCases' => ['zz'], 'relatedCases' => $legacy([['caseId' => 'zz', 'aardRelatie' => 'subject']])],
					'c' => ['id' => 'c', 'blockingCases' => ['zz'], 'associatedCases' => ['zz']],
				],
				'calls' => [$cleanup('zz')],
			],
			'cleanup, a counterpart with nothing to strip' => [
				'seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject']])]],
				'calls' => [$cleanup('a')],
			],
			'cleanup, a counterpart that cannot be read' => [
				'knobs' => ['findThrows' => ['b']],
				'seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject'], ['caseId' => 'c', 'aardRelatie' => 'subject']])], 'c' => ['id' => 'c', 'subjectCases' => ['a']]],
				'calls' => [$cleanup('a')],
			],
			'normalise, no id' => ['calls' => [$normalise('')]],
			'normalise, missing case' => ['calls' => [$normalise('x')]],
			'normalise, nothing to change' => ['seed' => ['a' => ['id' => 'a', 'subjectCases' => ['b'], 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject']])]], 'calls' => [$normalise('a')]],
			'normalise, legacy entries promoted' => [
				'seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'subject'], ['caseId' => 'c', 'aardRelatie' => 'vervolg'], ['caseId' => 'a', 'aardRelatie' => 'subject'], ['caseId' => 'b', 'aardRelatie' => 'friend'], ['caseId' => '', 'aardRelatie' => 'subject']])]],
				'calls' => [$normalise('a'), $normalise('a')],
			],
			'normalise, a waits-on entry moves to the primitive' => [
				'seed' => ['a' => ['id' => 'a', 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'waitsOn'], ['caseId' => 'c', 'aardRelatie' => 'samenhang']]), 'associatedCases' => ['c']]],
				'calls' => [$normalise('a')],
			],
			'normalise, only a waits-on entry already on the primitive' => [
				'seed' => ['a' => ['id' => 'a', 'blockingCases' => ['b'], 'relatedCases' => $legacy([['caseId' => 'b', 'aardRelatie' => 'waitsOn']])]],
				'calls' => [$normalise('a')],
			],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'case-relation-service', value: $observed);
	}//end testGoldenCaseRelationService()

	/**
	 * An IL10N that fills the placeholders and translates nothing.
	 *
	 * @return IL10N
	 */
	private static function untranslated(): IL10N {
		return new class implements IL10N {
			/**
			 * @param string                $text       The text.
			 * @param array<int, mixed>|mixed $parameters The values.
			 *
			 * @return string
			 */
			public function t(string $text, $parameters = []): string {
				return vsprintf($text, (array)$parameters);
			}

			/**
			 * @param string            $textSingular The singular.
			 * @param string            $textPlural   The plural.
			 * @param int               $count        The count.
			 * @param array<int, mixed> $parameters   The values.
			 *
			 * @return string
			 */
			public function n(string $textSingular, string $textPlural, int $count, array $parameters = []): string {
				return $textPlural;
			}

			/**
			 * @param string       $type    The type.
			 * @param mixed        $data    The data.
			 * @param array<mixed> $options The options.
			 *
			 * @return string|int|array<mixed>
			 */
			public function l(string $type, $data, array $options = []) {
				return '';
			}

			/**
			 * @return string
			 */
			public function getLanguageCode(): string {
				return 'en';
			}

			/**
			 * @return string
			 */
			public function getLocaleCode(): string {
				return 'en';
			}
		};
	}//end untranslated()
}//end class
