<?php

/**
 * The warnings a case type's rule publication returns when a flow does not land.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeRuleFlowDocument;
use OCA\Dossiq\Service\CaseType\CaseTypeRuleProjector;
use OCA\Dossiq\Service\CaseType\CaseTypeRuleSources;
use OCA\Dossiq\Service\CaseType\RuleConditionLogic;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publication never raises: what did not land comes back as warnings.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeRuleProjector
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeRuleSources
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeRuleFlowDocument
 * @uses   \OCA\Dossiq\Service\CaseType\RuleConditionLogic
 * @uses   \OCA\Dossiq\Service\CaseType\RuleFlowScope
 *
 * @spec openspec/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class CaseTypeRuleProjectorWarningsTest extends TestCase {

	private const FLOW_SERVICE = 'OCA\OpenRegister\Service\Flow\FlowService';

	private const VERSION_SERVICE = 'OCA\OpenRegister\Service\Flow\FlowVersionService';

	/**
	 * A rule that projects to one add-task flow.
	 *
	 * @var array<string, mixed>
	 */
	private const ADD_TASK = [
		'id' => 'welstand',
		'name' => 'Welstand',
		'enabled' => true,
		'when' => ['event' => 'saved'],
		'conditions' => [],
		'actions' => [['action' => 'addTask', 'title' => 'Welstandsadvies vragen']],
	];

	/**
	 * The logger, to assert what a failed flow records.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The projector over a case type with the given rules and OpenRegister services.
	 *
	 * @param array<int, mixed>     $rules    The rules.
	 * @param array<string, mixed>  $services OpenRegister services by class name; a non-object is served as is.
	 * @param mixed                 $title    The case type title, a string or a language map.
	 *
	 * @return CaseTypeRuleProjector The projector.
	 */
	private function projector(array $rules, array $services, mixed $title = 'Vergunning'): CaseTypeRuleProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('readCaseType')->willReturn(['title' => $title, 'rules' => $rules]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('x');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $name): bool => array_key_exists($name, $services));
		$container->method('get')->willReturnCallback(static fn (string $name): mixed => $services[$name]);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new CaseTypeRuleProjector(
			store: $store,
			settings: $settings,
			sources: new CaseTypeRuleSources(documents: new CaseTypeRuleFlowDocument(logic: new RuleConditionLogic())),
			container: $container,
			logger: $this->logger,
		);
	}//end projector()

	/**
	 * A flow service double.
	 *
	 * @param mixed      $page      What findAll answers.
	 * @param bool       $failSave  Whether save throws.
	 * @param bool       $failDelete Whether delete throws.
	 *
	 * @return object The double.
	 */
	private static function flowService(mixed $page, bool $failSave = false, bool $failDelete = false): object {
		return new class($page, $failSave, $failDelete) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];
			/** @var array<int, int> */
			public array $offsets = [];

			public function __construct(private mixed $page, private bool $failSave, private bool $failDelete) {
			}

			public function findAll(?string $app, ?string $slug, ?bool $enabled, int $limit, int $offset): mixed {
				$this->offsets[] = $offset;
				if (is_array($this->page) === false) {
					return $this->page;
				}

				return array_slice($this->page, $offset, $limit);
			}

			/**
			 * @param array<string, mixed> $data The flow.
			 */
			public function save(array $data, ?string $uuid): object {
				if ($this->failSave === true) {
					throw new RuntimeException('flow store down');
				}

				$this->saved[] = $data;
				return (object)[];
			}

			public function delete(string $uuid): void {
				if ($this->failDelete === true) {
					throw new RuntimeException('flow ' . $uuid . ' locked');
				}
			}
		};
	}//end flowService()

	/**
	 * A stored flow with this case type's marker.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object The flow.
	 */
	private static function stored(string $uuid): object {
		return new class($uuid) {
			public function __construct(private string $uuid) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}

			public function getNotes(): string {
				return 'dossiq:case-type-rule:type-1:old';
			}
		};
	}//end stored()

	/**
	 * An action with no flow step is left out of the plan and named in a warning.
	 *
	 * @return void
	 */
	public function testAnActionWithoutAFlowStepIsUnprojectedAndWarned(): void {
		$rule = self::ADD_TASK;
		$rule['actions'] = [['action' => 'unknownThing']];

		$projector = $this->projector(rules: [$rule], services: [self::FLOW_SERVICE => self::flowService(page: [])]);

		$plan = $projector->plan(caseTypeId: 'type-1');
		$this->assertSame([], $plan['flows']);
		$this->assertSame(['welstand: unknownThing'], $plan['unprojected']);

		$this->assertSame(
			['Rule action welstand: unknownThing is not carried out: it names no target, or a required document names no status to enter.'],
			$projector->publish(caseTypeId: 'type-1')
		);
	}//end testAnActionWithoutAFlowStepIsUnprojectedAndWarned()

	/**
	 * A case type title stored as a language map names the flow in its Dutch text, not "Array".
	 *
	 * @spec openspec/changes/archive/2026-11-25-translatable-titles-read-as-text/specs/case-types/spec.md
	 *
	 * @return void
	 */
	public function testACaseTypeTitleStoredAsALanguageMapNamesTheFlowInText(): void {
		$projector = $this->projector(
			rules: [self::ADD_TASK],
			services: [self::FLOW_SERVICE => self::flowService(page: [])],
			title: ['en' => 'Permit', 'nl' => 'Vergunning'],
		);

		$plan = $projector->plan(caseTypeId: 'type-1');

		$this->assertCount(expectedCount: 1, haystack: $plan['flows']);
		$this->assertStringStartsWith(prefix: 'Vergunning: ', string: $plan['flows'][0]['name']);
	}//end testACaseTypeTitleStoredAsALanguageMapNamesTheFlowInText()

	/**
	 * Without a flow engine, rules that would project say so; no rules say nothing.
	 *
	 * @return void
	 */
	public function testWithoutAFlowEngineTheRulesAreNotPublished(): void {
		$this->assertSame(
			['The rules were not published: this instance has no OpenRegister flow engine.'],
			$this->projector(rules: [self::ADD_TASK], services: [])->publish(caseTypeId: 'type-1')
		);
		$this->assertSame([], $this->projector(rules: [], services: [])->publish(caseTypeId: 'type-1'));
	}//end testWithoutAFlowEngineTheRulesAreNotPublished()

	/**
	 * A container entry that is not an object counts as no flow engine.
	 *
	 * @return void
	 */
	public function testANonObjectServiceCountsAsNoFlowEngine(): void {
		$this->assertSame(
			['The rules were not published: this instance has no OpenRegister flow engine.'],
			$this->projector(rules: [self::ADD_TASK], services: [self::FLOW_SERVICE => 'not a service'])->publish(caseTypeId: 'type-1')
		);
	}//end testANonObjectServiceCountsAsNoFlowEngine()

	/**
	 * An earlier flow that cannot be deleted is a warning, and the new flow is still created.
	 *
	 * @return void
	 */
	public function testAFlowThatCannotBeRemovedIsWarned(): void {
		$flows = self::flowService(page: [self::stored(uuid: 'old-1')], failDelete: true);

		$warnings = $this->projector(rules: [self::ADD_TASK], services: [self::FLOW_SERVICE => $flows])->publish(caseTypeId: 'type-1');

		$this->assertSame(['An earlier rule flow could not be removed: flow old-1 locked'], $warnings);
		$this->assertSame(['dossiq:case-type-rule:type-1:welstand'], array_column($flows->saved, 'notes'));
	}//end testAFlowThatCannotBeRemovedIsWarned()

	/**
	 * A flow that cannot be saved is logged and returned as a warning naming the rule.
	 *
	 * @return void
	 */
	public function testAFlowThatCannotBeSavedIsLoggedAndWarned(): void {
		$flows = self::flowService(page: [], failSave: true);
		$projector = $this->projector(rules: [self::ADD_TASK], services: [self::FLOW_SERVICE => $flows]);

		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: a case type rule flow could not be published',
			$this->callback(static fn (array $context): bool => $context['exception'] === 'flow store down'
				&& $context['flow'] === 'dossiq:case-type-rule:type-1:welstand')
		);

		$warnings = $projector->publish(caseTypeId: 'type-1');

		$this->assertSame(['Rule Vergunning: Welstand was not published: flow store down'], $warnings);
	}//end testAFlowThatCannotBeSavedIsLoggedAndWarned()

	/**
	 * A flow listing that is not a list stops the search for earlier flows; a full page reads the next.
	 *
	 * @return void
	 */
	public function testTheFlowListingIsPagedAndANonListStopsIt(): void {
		$broken = self::flowService(page: null);
		$this->assertSame([], $this->projector(rules: [self::ADD_TASK], services: [self::FLOW_SERVICE => $broken])->publish(caseTypeId: 'type-1'));
		$this->assertSame([0], $broken->offsets);
		$this->assertCount(1, $broken->saved);

		$full = self::flowService(page: array_map(static fn (int $i): object => self::stored(uuid: 'f' . $i), range(1, 100)));
		$this->projector(rules: [], services: [self::FLOW_SERVICE => $full])->publish(caseTypeId: 'type-1');
		$this->assertSame([0, 100], $full->offsets);
	}//end testTheFlowListingIsPagedAndANonListStopsIt()
}//end class
