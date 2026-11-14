<?php

/**
 * When a case may not be hung under another as its subject.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Relation\SubjectLinkRule;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A subject already held elsewhere, a subject cycle, and the links that pass.
 *
 * @covers \OCA\Dossiq\Service\Relation\SubjectLinkRule
 * @uses \OCA\Dossiq\Service\Notice\CaseActNotices
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationCodec
 * @uses \OCA\Dossiq\Service\Relation\CaseRelationStore
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 * @uses \OCA\Dossiq\Service\Queue\QueueCaseFacts
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class SubjectLinkRuleTest extends TestCase {

	private function store(FakeObjectService $objects): CaseRelationStore {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'case_schema' => '9'][$key] ?? $default)
		);
		$config->method('hasKey')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		$logger = $this->createMock(LoggerInterface::class);

		return new CaseRelationStore(new SettingsService($config, $apps, $container, $logger), $logger);
	}

	/**
	 * A rule over cases that hold their subjects, given as holder => subject ids.
	 *
	 * @param array<string, list<string>> $holds Holder id => the subject ids it holds.
	 * @param bool                        $typed Hold through subjectCases instead of relatedCases.
	 */
	private function rule(array $holds, bool $typed = false): SubjectLinkRule {
		$objects = [];
		$usedBy  = [];
		foreach ($holds as $holder => $subjects) {
			$case = ['id' => $holder, 'identifier' => 'Z-'.$holder];
			if ($typed === true) {
				$case['subjectCases'] = $subjects;
			} else {
				$case['relatedCases'] = array_map(static fn (string $s): array => ['caseId' => $s, 'aardRelatie' => 'subject'], $subjects);
			}

			$objects[$holder] = $case;
			foreach ($subjects as $subject) {
				$usedBy[$subject]['results'][] = ['id' => $holder];
			}
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new SubjectLinkRule($this->store(new FakeObjectService(objects: $objects, usedBy: $usedBy)), new CaseRelationCodec(), new CaseActNotices($l10n));
	}

	private function pair(): array {
		return ['origin' => ['id' => 'a', 'identifier' => 'Z-a'], 'target' => ['id' => 'b', 'title' => 'Subject b']];
	}

	public function testAFreeSubjectMayBeLinked(): void {
		$this->assertNull($this->rule([])->refusal($this->pair(), ['from' => 'a', 'to' => 'b']));
	}

	public function testRelinkingToTheSameHolderIsNoRefusal(): void {
		$this->assertNull($this->rule(['a' => ['b']])->refusal($this->pair(), ['from' => 'a', 'to' => 'b']));
	}

	public function testASubjectHeldByAnotherCaseIsRefused(): void {
		foreach ([false, true] as $typed) {
			$refusal = $this->rule(['x' => ['b']], $typed)->refusal($this->pair(), ['from' => 'a', 'to' => 'b']);

			$this->assertSame(false, $refusal['ok']);
			$this->assertSame('subject_taken', $refusal['reason']);
			$this->assertSame('Subject b', $refusal['target']);
			$this->assertSame('Z-x', $refusal['other']);
			$this->assertSame('Not linked: Subject b already hangs as the subject under Z-x. A case can hang as the subject under one case only.', $refusal['message']);
			$this->assertSame(['variant' => 'error', 'role' => 'alert'], array_slice($refusal['notice'], 0, 2));
		}
	}

	public function testHangingACaseUnderItsOwnSubjectChainIsRefused(): void {
		// b holds c, c holds a: a hangs (two steps up) under b, so b may not become a's subject.
		$refusal = $this->rule(['b' => ['c'], 'c' => ['a']])->refusal($this->pair(), ['from' => 'a', 'to' => 'b']);

		$this->assertSame('subject_cycle', $refusal['reason']);
		$this->assertSame('Z-a', $refusal['other']);
		$this->assertSame('Not linked: Z-a already hangs, directly or further up, under Subject b. A subject cannot hang under its own subject.', $refusal['message']);
	}

	public function testALoopElsewhereInTheChainEndsTheWalk(): void {
		// a hangs under c, c under d, d under c again: b is never reached.
		$this->assertNull($this->rule(['c' => ['a', 'd'], 'd' => ['c']])->refusal($this->pair(), ['from' => 'a', 'to' => 'b']));
	}
}
