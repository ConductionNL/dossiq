<?php

/**
 * The incoming rows a subject rule skips, and how it names a case.
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
 * Rows without an id, rows naming the case itself, unreadable cases and cases
 * that link without holding the subject are no holder; a case without a number
 * or title is named by its uuid.
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
class SubjectLinkRuleEdgesTest extends TestCase {

	private FakeObjectService $objects;

	/**
	 * A rule over the given cases and incoming rows.
	 *
	 * @param array<string, mixed> $objects Cases by uuid.
	 * @param array<string, array> $usedBy  Incoming rows by uuid.
	 */
	private function rule(array $objects, array $usedBy): SubjectLinkRule {
		$this->objects = new FakeObjectService(objects: $objects, usedBy: $usedBy);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => '5', 'case_schema' => '9'][$key] ?? $default)
		);
		$config->method('hasKey')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);

		$logger = $this->createMock(LoggerInterface::class);
		$store  = new CaseRelationStore(new SettingsService($config, $apps, $container, $logger), $logger);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new SubjectLinkRule($store, new CaseRelationCodec(), new CaseActNotices($l10n));
	}

	/**
	 * The uuids the rule looked a case up by, in order.
	 *
	 * @return list<string>
	 */
	private function lookedUp(): array {
		return array_values(array_map(
			static fn (array $call): string => $call[1],
			array_filter($this->objects->calls, static fn (array $call): bool => $call[0] === 'find')
		));
	}

	public function testRowsThatNameNoOtherReadableHolderAreSkipped(): void {
		$rule = $this->rule(
			objects: [
				// Links to b, but as a plain relation, not as its subject.
				'y' => ['id' => 'y', 'identifier' => 'Z-y', 'relatedCases' => [['caseId' => 'b', 'aardRelatie' => 'vervolg']]],
			],
			usedBy: ['b' => ['results' => [['id' => ''], ['uuid' => 'b'], ['id' => 'ghost'], ['id' => 'y']]]],
		);

		$this->assertNull($rule->refusal(
			['origin' => ['id' => 'a', 'identifier' => 'Z-a'], 'target' => ['id' => 'b', 'identifier' => 'Z-b']],
			['from' => 'a', 'to' => 'b']
		));
		// Neither the row without an id nor the row naming b itself is read.
		$this->assertSame(['ghost', 'y'], array_slice($this->lookedUp(), 0, 2));
	}

	public function testTheHolderBehindSkippedRowsIsStillFound(): void {
		$refusal = $this->rule(
			objects: ['x' => ['id' => 'x', 'title' => 'Holder x', 'relatedCases' => [['caseId' => 'b', 'aardRelatie' => 'subject']]]],
			usedBy: ['b' => ['results' => [['id' => ''], ['id' => 'b'], ['id' => 'ghost'], ['uuid' => 'x']]]],
		)->refusal(['origin' => ['id' => 'a'], 'target' => ['id' => 'b', 'identifier' => 'Z-b']], ['from' => 'a', 'to' => 'b']);

		$this->assertSame('subject_taken', $refusal['reason']);
		$this->assertSame('Z-b', $refusal['target']);
		$this->assertSame('Holder x', $refusal['other']);
	}

	public function testACaseWithoutNumberOrTitleIsNamedByItsUuid(): void {
		$refusal = $this->rule(
			objects: ['x' => ['@self' => ['id' => 'x'], 'identifier' => '  ', 'relatedCases' => [['caseId' => 'b', 'aardRelatie' => 'subject']]]],
			usedBy: ['b' => ['results' => [['id' => 'x']]]],
		)->refusal(['origin' => ['id' => 'a'], 'target' => ['uuid' => 'b', 'title' => '']], ['from' => 'a', 'to' => 'b']);

		$this->assertSame('subject_taken', $refusal['reason']);
		$this->assertSame('b', $refusal['target']);
		$this->assertSame('x', $refusal['other']);
		$this->assertSame('Not linked: b already hangs as the subject under x. A case can hang as the subject under one case only.', $refusal['message']);
	}
}
