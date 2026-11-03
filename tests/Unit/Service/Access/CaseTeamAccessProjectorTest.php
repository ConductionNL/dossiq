<?php

/**
 * What the handling team projector writes onto the live case schema.
 *
 * The schema is a stand-in that records what it is given; the case types come
 * from a store stand-in; everything between them is the real code, including
 * the shipped block read from the fragment-merged register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Access\CaseTeamAccessProjector
 * @uses \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessProjectorTest extends TestCase {

	/**
	 * The live case schema stand-in.
	 *
	 * @var object
	 */
	private object $schema;

	/**
	 * The schema mapper stand-in, counting its writes.
	 *
	 * @var object
	 */
	private object $mapper;

	/**
	 * The shipped baseline store stand-in, or null when OpenRegister has none.
	 *
	 * @var object|null
	 */
	private ?object $baselines = null;

	/**
	 * Every warning the projector logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build the schema and the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new class {
			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $authorization = ['roles' => ['behandelaar' => ['behandelaars']]];

			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			public function setAuthorization(?array $authorization): void {
				$this->authorization = $authorization;
			}
		};

		$this->mapper = new class {
			public int $writes = 0;

			public function update(object $schema): object {
				$this->writes++;
				return $schema;
			}
		};
	}//end setUp()

	/**
	 * A projector over the given case type rows.
	 *
	 * @param array<int, array<string, mixed>> $rows       The case types the store answers.
	 * @param bool                             $hasMapper  Whether OpenRegister is installed.
	 *
	 * @return CaseTeamAccessProjector The projector.
	 */
	private function projector(array $rows, bool $hasMapper = true): CaseTeamAccessProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('everyCaseType')->with(['_rbac' => false, '_multitenancy' => false])->willReturn($rows);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));

		$slugs = $this->createMock(SchemaSlugResolver::class);
		$slugs->method('resolve')->willReturn($this->schema);

		$services = [];
		if ($hasMapper === true) {
			$services['OCA\\OpenRegister\\Db\\SchemaMapper'] = $this->mapper;
		}

		if ($this->baselines !== null) {
			$services['OCA\\OpenRegister\\Service\\ShippedBaseline\\ShippedBaselineStore'] = $this->baselines;
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new CaseTeamAccessProjector(
			store: $store,
			handling: new CaseTypeHandling(),
			rules: new CaseTeamAccessRules(),
			slugs: $slugs,
			fragments: new RegisterFragmentMerger(),
			container: $container,
			logger: $this->warningLog(),
		);
	}//end projector()

	/**
	 * A logger that keeps the warnings in {@see self::$warnings}.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function warningLog(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return $logger;
	}//end warningLog()

	/**
	 * The shipped case block closes the case to everybody but the assignee.
	 *
	 * Read from the real fragment-merged register: the roles the notification
	 * dispatcher reads stay, the assignee and the background account read and
	 * update, the staff group creates, administrators alone delete, and no
	 * entry grants `authenticated` or `public` without a match.
	 *
	 * @return void
	 */
	public function testTheShippedCaseBlockIsClosed(): void {
		$block = $this->projector(rows: [])->shippedBlock();

		$assignee = ['group' => 'authenticated', 'match' => ['assignee' => '$userId']];
		$this->assertArrayHasKey('roles', $block);
		$this->assertSame([$assignee, 'dossiq-background-service'], $block['read']);
		$this->assertSame([$assignee, 'dossiq-background-service'], $block['update']);
		$this->assertSame(['dossiq-medewerkers', 'dossiq-background-service'], $block['create']);
		$this->assertSame(['admin'], $block['delete']);

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertNotContains('authenticated', $block[$action], $action . ' grants every signed in account');
			$this->assertNotContains('public', $block[$action], $action . ' grants anonymous callers');
		}
	}//end testTheShippedCaseBlockIsClosed()

	/**
	 * A case type's handling teams reach the live schema.
	 *
	 * The default group and the declared teams both count, through the one
	 * reader `CaseTypeHandling::teams()`.
	 *
	 * @return void
	 */
	public function testACaseTypesHandlingTeamsReachTheLiveSchema(): void {
		$written = $this->projector(
			rows: [
				['id' => 'ct-permit', 'handling' => ['defaultGroup' => 'vergunningen', 'teams' => ['handhaving']]],
				['id' => 'ct-woo', 'handling' => ['teams' => ['woo']]],
			]
		)->reapply();

		$this->assertTrue($written);
		$this->assertSame(1, $this->mapper->writes);

		$read = $this->schema->authorization['read'];
		$this->assertContains(['group' => 'vergunningen', 'match' => ['caseType' => ['$in' => ['ct-permit']]]], $read);
		$this->assertContains(['group' => 'handhaving', 'match' => ['caseType' => ['$in' => ['ct-permit']]]], $read);
		$this->assertContains(['group' => 'woo', 'match' => ['caseType' => ['$in' => ['ct-woo']]]], $read);
		$this->assertSame(['admin'], $this->schema->authorization['delete']);
	}//end testACaseTypesHandlingTeamsReachTheLiveSchema()

	/**
	 * The live block is replaced, not added to, so a removed team goes.
	 *
	 * The schema starts with a stale rule for a team no case type names any
	 * more. Building on the live block would keep it.
	 *
	 * @return void
	 */
	public function testAStaleTeamRuleOnTheLiveSchemaIsRemoved(): void {
		$stale = ['group' => 'oud-team', 'match' => ['caseType' => ['$in' => ['ct-permit']]]];
		$this->schema->authorization = ['read' => [$stale]];

		$this->projector(rows: [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]])->reapply();

		$this->assertNotContains($stale, $this->schema->authorization['read']);
	}//end testAStaleTeamRuleOnTheLiveSchemaIsRemoved()

	/**
	 * Nothing changed, nothing written.
	 *
	 * Every case type save calls the projector, so it must not rewrite the
	 * schema when the block is the same.
	 *
	 * @return void
	 */
	public function testAnUnchangedBlockIsNotWrittenAgain(): void {
		$rows = [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]];
		$projector = $this->projector(rows: $rows);

		$this->assertTrue($projector->reapply());
		$this->assertFalse($projector->reapply());
		$this->assertSame(1, $this->mapper->writes);
	}//end testAnUnchangedBlockIsNotWrittenAgain()

	/**
	 * A baseline store over one recorded baseline, keeping every record call.
	 *
	 * @param array<string, mixed>|null $baseline The recorded baseline, or null for none.
	 * @param bool                      $records  Whether a record succeeds.
	 *
	 * @return object The store.
	 */
	private static function baselineStore(?array $baseline, bool $records = true): object {
		return new class($baseline, $records) {
			/**
			 * Every record call.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $recorded = [];

			/**
			 * @param array<string, mixed>|null $baseline The recorded baseline.
			 * @param bool                      $records  Whether a record succeeds.
			 */
			public function __construct(public ?array $baseline, private bool $records) {
			}

			/**
			 * @param string $slug The schema slug.
			 *
			 * @return string The subject reference.
			 */
			public function schemaSubject(string $slug): string {
				return 'schema:' . $slug;
			}

			/**
			 * @param string $subject The subject reference.
			 *
			 * @return array<string, mixed>|null The baseline of the case schema, or null.
			 */
			public function read(string $subject): ?array {
				if ($subject !== 'schema:case') {
					return null;
				}

				return $this->baseline;
			}

			/**
			 * @param string               $subject    The subject reference.
			 * @param array<string, mixed> $definition The definition.
			 * @param string               $app        The app.
			 * @param string               $appVersion The app version.
			 *
			 * @return bool Whether it was recorded.
			 */
			public function record(string $subject, array $definition, string $app, string $appVersion): bool {
				$this->recorded[] = ['subject' => $subject, 'definition' => $definition, 'app' => $app, 'appVersion' => $appVersion];
				if ($this->records === true) {
					$this->baseline = ['definition' => $definition, 'app' => $app, 'appVersion' => $appVersion, 'recordedAt' => 'now'];
				}

				return $this->records;
			}
		};
	}//end baselineStore()

	/**
	 * The case schema as the app shipped it, with the shipped block.
	 *
	 * @param array<string, mixed> $authorization The schema's authorization.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function shippedDefinition(array $authorization): array {
		return [
			'slug' => 'case',
			'title' => 'Case',
			'properties' => ['title' => ['type' => 'string']],
			'authorization' => $authorization,
		];
	}//end shippedDefinition()

	/**
	 * A baseline as OpenRegister records it, of release 1.4.0.
	 *
	 * @param array<string, mixed> $authorization The schema's authorization.
	 *
	 * @return array<string, mixed> The baseline.
	 */
	private static function baselineOf(array $authorization): array {
		return [
			'definition' => self::shippedDefinition(authorization: $authorization),
			'app' => 'dossiq',
			'appVersion' => '1.4.0',
			'recordedAt' => 'then',
		];
	}//end baselineOf()

	/**
	 * The written block becomes the case schema's shipped authorization, and nothing else changes.
	 *
	 * @return void
	 */
	public function testTheWrittenBlockBecomesTheShippedBaseline(): void {
		$shipped = $this->projector(rows: [])->shippedBlock();
		$this->baselines = self::baselineStore(
			baseline: self::baselineOf(authorization: $shipped)
		);

		$this->projector(rows: [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]])->reapply();

		self::assertCount(expectedCount: 1, haystack: $this->baselines->recorded);
		$recorded = $this->baselines->recorded[0];
		self::assertSame(expected: 'schema:case', actual: $recorded['subject']);
		self::assertSame(expected: $this->schema->authorization, actual: $recorded['definition']['authorization']);
		self::assertSame(
			expected: array_diff_key(self::shippedDefinition(authorization: $shipped), ['authorization' => true]),
			actual: array_diff_key($recorded['definition'], ['authorization' => true]),
		);
		self::assertSame(expected: ['dossiq', '1.4.0'], actual: [$recorded['app'], $recorded['appVersion']]);
	}//end testTheWrittenBlockBecomesTheShippedBaseline()

	/**
	 * A live block that is already right still aligns a baseline that is not.
	 *
	 * An instance whose rules were written before baselines were aligned has
	 * the right live block and a stale baseline.
	 *
	 * @return void
	 */
	public function testAStaleBaselineIsAlignedWithoutRewritingTheSchema(): void {
		$rows = [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]];
		$this->projector(rows: $rows)->reapply();
		$shipped = $this->projector(rows: [])->shippedBlock();
		$this->baselines = self::baselineStore(
			baseline: self::baselineOf(authorization: $shipped)
		);

		self::assertFalse(condition: $this->projector(rows: $rows)->reapply());
		self::assertSame(expected: 1, actual: $this->mapper->writes);
		self::assertSame(expected: $this->schema->authorization, actual: $this->baselines->baseline['definition']['authorization']);
	}//end testAStaleBaselineIsAlignedWithoutRewritingTheSchema()

	/**
	 * An aligned baseline is not recorded again, and no baseline is never invented.
	 *
	 * @return void
	 */
	public function testNothingIsRecordedWhenAlignedOrWithoutABaseline(): void {
		$rows = [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]];
		$this->projector(rows: $rows)->reapply();
		self::assertSame(expected: [], actual: $this->warnings, message: 'An OpenRegister without baselines is an ordinary state.');

		$this->baselines = self::baselineStore(baseline: null);
		$this->projector(rows: $rows)->reapply();
		self::assertSame(expected: [], actual: $this->baselines->recorded);
		self::assertSame(expected: [], actual: $this->warnings, message: 'A schema without a baseline is an ordinary state.');

		$this->baselines = self::baselineStore(
			baseline: self::baselineOf(authorization: $this->schema->authorization)
		);
		$this->projector(rows: $rows)->reapply();
		self::assertSame(expected: [], actual: $this->baselines->recorded);
	}//end testNothingIsRecordedWhenAlignedOrWithoutABaseline()

	/**
	 * A baseline that cannot be recorded leaves the schema write standing.
	 *
	 * @return void
	 */
	public function testAFailedRecordKeepsTheSchemaWrite(): void {
		$shipped = $this->projector(rows: [])->shippedBlock();
		$this->baselines = self::baselineStore(
			baseline: self::baselineOf(authorization: $shipped),
			records: false,
		);

		self::assertTrue(condition: $this->projector(rows: [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]])->reapply());
		self::assertSame(expected: 1, actual: $this->mapper->writes);
		self::assertCount(expectedCount: 1, haystack: $this->baselines->recorded);
		self::assertCount(expectedCount: 1, haystack: $this->warnings);
	}//end testAFailedRecordKeepsTheSchemaWrite()

	/**
	 * After a projector rewrite, OpenRegister's upgrade merge applies a shipped
	 * authorization change without a conflict. With the baseline left as
	 * shipped, the same change is kept out as a conflict.
	 *
	 * Runs OpenRegister's own merge, so it needs OpenRegister beside this app.
	 *
	 * @return void
	 */
	public function testAShippedChangeAfterARewriteMergesWithoutAConflict(): void {
		$merge = 'OCA\\OpenRegister\\Service\\ShippedBaseline\\GuardedDescriptorMerge';
		if (class_exists($merge) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s GuardedDescriptorMerge is not beside this app.');
		}

		$shipped = $this->projector(rows: [])->shippedBlock();
		$asShipped = self::baselineOf(authorization: $shipped);
		$this->baselines = self::baselineStore(baseline: $asShipped);
		$this->projector(rows: [['id' => 'ct-permit', 'handling' => ['teams' => ['vergunningen']]]])->reapply();

		$live = self::shippedDefinition(authorization: $this->schema->authorization);
		$next = $shipped;
		$next['delete'] = ['admin', 'dossiq-beheerders'];
		$incoming = self::shippedDefinition(authorization: $next);
		$parts = 'OCA\\OpenRegister\\Service\\ShippedBaseline\\DescriptorParts';
		$comparator = 'OCA\\OpenRegister\\Service\\ShippedBaseline\\DivergenceComparator';
		$flattener = new $parts();
		$engine = new $merge(parts: $flattener, comparator: new $comparator(parts: $flattener));

		$aligned = $engine->merge(baseline: $this->baselines->baseline['definition'], live: $live, incoming: $incoming);
		self::assertSame(expected: [], actual: $aligned['conflicts']);
		self::assertEqualsCanonicalizing(expected: ['admin', 'dossiq-beheerders'], actual: $aligned['merged']['authorization']['delete']);

		$unaligned = $engine->merge(baseline: $asShipped['definition'], live: $live, incoming: $incoming);
		self::assertNotSame(expected: [], actual: $unaligned['conflicts']);
		self::assertEqualsCanonicalizing(expected: ['admin'], actual: $unaligned['merged']['authorization']['delete']);
	}//end testAShippedChangeAfterARewriteMergesWithoutAConflict()

	/**
	 * Without OpenRegister nothing is written and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsWritten(): void {
		$this->assertFalse($this->projector(rows: [], hasMapper: false)->reapply());
		$this->assertSame(0, $this->mapper->writes);
	}//end testWithoutOpenRegisterNothingIsWritten()
}//end class
