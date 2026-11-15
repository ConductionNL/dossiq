<?php

/**
 * Unit tests for the retired-node translations that refuse, and for stepsFor.
 *
 * The translator and its template, document and webhook collaborators are
 * real. The configured register and schema, the container and the
 * decision-table store are instance data and answered by doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeTranslator
 * @uses \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @uses \OCA\Dossiq\Service\Flow\RetiredDocumentSteps
 * @uses \OCA\Dossiq\Service\Flow\RetiredWebhookSteps
 * @uses \OCA\Dossiq\Service\Flow\UnmappableStep
 */
class RetiredNodeTranslatorRefusalsTest extends TestCase {

	/**
	 * A translator whose container answers with the given object.
	 *
	 * @param mixed $resolved What the container returns for the decision-table store.
	 *
	 * @return RetiredNodeTranslator
	 */
	private function translator(mixed $resolved = null): RetiredNodeTranslator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => '12', 'case_schema' => '34'][$key] ?? '')
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($resolved ?? new stdClass());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$syntax = new RetiredTemplateSyntax();
		$webhooks = new RetiredWebhookSteps($this->createMock(IEventDispatcher::class), $this->createMock(IUserSession::class), $syntax);

		return new RetiredNodeTranslator($settings, $container, $l10n, $syntax, new RetiredDocumentSteps($syntax), $webhooks);
	}//end translator()

	/**
	 * A decision-table store whose find answers with the given table.
	 *
	 * @param array<string, mixed>|null $table The table, or null for "store not available".
	 *
	 * @return StoredDecisionTables
	 */
	private function tables(?array $table): StoredDecisionTables {
		$tables = $this->getMockBuilder(StoredDecisionTables::class)->disableOriginalConstructor()->onlyMethods(['find', 'keyFor'])->getMock();
		$tables->method('keyFor')->willReturnCallback(static fn (string $key): string => 'dossiq.' . $key);
		$tables->method('find')->willReturn($table);

		return $tables;
	}//end tables()

	/**
	 * Assert that translating the config refuses with a reason containing the fragment.
	 *
	 * @param RetiredNodeTranslator $translator  The translator.
	 * @param string                $translation The translation key.
	 * @param array<string, mixed>  $config      The retired configuration.
	 * @param string                $fragment    Part of the expected reason.
	 *
	 * @return void
	 */
	private function assertRefused(RetiredNodeTranslator $translator, string $translation, array $config, string $fragment): void {
		try {
			$translator->translate(translation: $translation, config: $config);
			self::fail('Expected the translation to be refused.');
		} catch (UnmappableStep $e) {
			self::assertStringContainsString($fragment, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * A row without a translation keeps the configuration under its replacement.
	 *
	 * @return void
	 */
	public function testStepsForARenamedRowKeepsTheConfiguration(): void {
		$steps = $this->translator()->stepsFor(
			row: ['replacement' => 'openregister.end', 'reason' => 'renamed'],
			config: ['keep' => 'me']
		);

		self::assertSame([['type' => 'openregister.end', 'config' => ['keep' => 'me']]], $steps);
	}//end testStepsForARenamedRowKeepsTheConfiguration()

	/**
	 * A row with neither translation nor replacement is refused with its reason.
	 *
	 * @return void
	 */
	public function testStepsForARetiredRowWithoutReplacementIsRefused(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('nothing replaces it (gone for good)');

		$this->translator()->stepsFor(row: ['replacement' => null, 'reason' => 'gone for good'], config: []);
	}//end testStepsForARetiredRowWithoutReplacementIsRefused()

	/**
	 * A row with a translation is translated, not renamed.
	 *
	 * @return void
	 */
	public function testStepsForATranslatedRowTranslates(): void {
		$steps = $this->translator()->stepsFor(
			row: ['replacement' => 'openregister.send-notification', 'reason' => 'moved', 'translation' => RetiredNodeMap::NOTIFY_TRANSITION],
			config: ['userId' => 'jan']
		);

		self::assertSame('openregister.send-notification', $steps[0]['type']);
		self::assertSame(['jan'], $steps[0]['config']['recipients']);
		self::assertSame('A case you handle changed status', $steps[0]['config']['title']);
		self::assertSame('Open the case to see what changed.', $steps[0]['config']['message']);
	}//end testStepsForATranslatedRowTranslates()

	/**
	 * A role notification whose role slug is not a plain field is refused.
	 *
	 * @return void
	 */
	public function testANotifyRoleWithAnUnreadableRoleIsRefused(): void {
		$this->assertRefused($this->translator(), RetiredNodeMap::NOTIFY_ROLE, ['roleSlug' => 'case.behandelaar'], 'no role field');
	}//end testANotifyRoleWithAnUnreadableRoleIsRefused()

	/**
	 * A set-field step without a plain field, or with a placeholder-shaped value, is refused.
	 *
	 * @return void
	 */
	public function testASetFieldThatCannotBeCarriedIsRefused(): void {
		$translator = $this->translator();

		$this->assertRefused($translator, RetiredNodeMap::SET_FIELD, ['field' => 'a.b', 'value' => 'x'], 'no field that can be written');
		$this->assertRefused($translator, RetiredNodeMap::SET_FIELD, ['field' => 'status', 'value' => 'Hi {{ name }}'], 'holds "{{"');
	}//end testASetFieldThatCannotBeCarriedIsRefused()

	/**
	 * A decision step without a table key is refused before the store is asked.
	 *
	 * @return void
	 */
	public function testADecisionWithoutATableIsRefused(): void {
		$this->assertRefused($this->translator(), RetiredNodeMap::DECISION, ['decisionKey' => '  '], 'names no decision table');
	}//end testADecisionWithoutATableIsRefused()

	/**
	 * When the container does not answer with the store, the decision is refused.
	 *
	 * @return void
	 */
	public function testADecisionIsRefusedWhenTheStoreCannotBeResolved(): void {
		$this->assertRefused($this->translator(new stdClass()), RetiredNodeMap::DECISION, ['decisionKey' => 'weg'], '"weg" could not be looked up');
	}//end testADecisionIsRefusedWhenTheStoreCannotBeResolved()

	/**
	 * When the store answers without a table, the decision is refused.
	 *
	 * @return void
	 */
	public function testADecisionIsRefusedWhenTheStoreReturnsNoTable(): void {
		$this->assertRefused(
			$this->translator($this->tables(null)),
			RetiredNodeMap::DECISION,
			['decisionKey' => 'weg'],
			'store is not available to read "dossiq.weg"'
		);
	}//end testADecisionIsRefusedWhenTheStoreReturnsNoTable()

	/**
	 * A table without named outputs leaves nothing to write and is refused.
	 *
	 * @return void
	 */
	public function testADecisionWhoseTableDeclaresNoOutputsIsRefused(): void {
		$this->assertRefused(
			$this->translator($this->tables(['key' => 'weg', 'outputs' => [['name' => ' '], ['label' => 'x']]])),
			RetiredNodeMap::DECISION,
			['decisionKey' => 'weg'],
			'declares no outputs to write'
		);
	}//end testADecisionWhoseTableDeclaresNoOutputsIsRefused()

	/**
	 * A decision with outputs becomes a table step and a write of the mapped fields.
	 *
	 * @return void
	 */
	public function testADecisionWritesItsMappedOutputs(): void {
		$steps = $this->translator($this->tables(['key' => 'weg', 'outputs' => [['name' => 'route'], ['name' => 'risk']]]))->translate(
			translation: RetiredNodeMap::DECISION,
			config: ['decisionKey' => 'weg', 'outputMapping' => ['risk' => 'riskLevel'], 'inputMapping' => []]
		);

		self::assertSame(['tableRef' => 'dossiq.weg', 'outputMapping' => ['risk' => 'riskLevel']], $steps[0]['config']);
		self::assertSame(['route' => '{{ route }}', 'riskLevel' => '{{ riskLevel }}'], $steps[1]['config']['fields']);
		self::assertSame('actionResult', $steps[1]['config']['output']);
	}//end testADecisionWritesItsMappedOutputs()

	/**
	 * An unknown translation key is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownTranslationIsRefused(): void {
		$this->assertRefused($this->translator(), 'teleport', [], 'no translation is known for "teleport"');
	}//end testAnUnknownTranslationIsRefused()

	/**
	 * A callWebhook step goes to the webhook translation, which refuses when nothing provides a source.
	 *
	 * @return void
	 */
	public function testAWebhookActionWithoutASourceIsRefused(): void {
		$this->assertRefused(
			$this->translator(),
			RetiredNodeMap::WEBHOOK_ACTION,
			['url' => 'https://hooks.example.org/in'],
			'Integriq gave no source for https://hooks.example.org: nothing answered the request'
		);
	}//end testAWebhookActionWithoutASourceIsRefused()
}//end class
