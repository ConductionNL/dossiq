<?php

/**
 * Unit tests for the retired-node translations.
 *
 * The translator, the template syntax and the document steps are real. Only
 * the configured register and schema and the decision-table store are
 * answered by doubles, because those are data on an instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Dmn\DecisionTableService;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeTranslator
 * @covers \OCA\Dossiq\Service\Flow\RetiredDocumentSteps
 * @covers \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeMap
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeRewriter
 */
class RetiredNodeTranslatorTest extends TestCase {

	/**
	 * The translator, with a register and case schema configured.
	 *
	 * @param array<string, mixed>|null $table The decision table the store answers with.
	 * @param bool                      $configured Whether register and schema are set.
	 *
	 * @return RetiredNodeTranslator The translator.
	 */
	private function translator(?array $table = null, bool $configured = true): RetiredNodeTranslator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => $configured === true
				? (['register' => '12', 'case_schema' => '34'][$key] ?? '')
				: ''
		);

		$tables = $this->createMock(DecisionTableService::class);
		$tables->method('findByKey')->willReturn($table);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($tables);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$syntax = new RetiredTemplateSyntax();

		return new RetiredNodeTranslator($settings, $container, $l10n, $syntax, new RetiredDocumentSteps($syntax));
	}//end translator()

	/**
	 * Every row the task names is in the shipped table, pointing where Ruben decided.
	 *
	 * @return void
	 */
	public function testTheShippedTableMapsEveryRemovedNodeToItsOwner(): void {
		$map = new RetiredNodeMap();

		$expected = [
			'dossiq.action.sendEmail' => 'openregister.send-email',
			'dossiq.sendEmail' => 'openregister.send-email',
			'dossiq.action.notifyRole' => 'openregister.send-notification',
			'dossiq.notify' => 'openregister.send-notification',
			'dossiq.setField' => 'openregister.object-write',
			'dossiq.evaluateDecision' => 'openregister.decision-table',
			'dossiq.action.createDocument' => 'filinq.generate-document',
			'dossiq.action.mergeTemplate' => 'filinq.generate-document',
			'dossiq.requestDecision' => 'decidiq.request-decision',
			'dossiq.webhook' => null,
			'dossiq.action.callWebhook' => null,
		];

		foreach ($expected as $type => $replacement) {
			$row = $map->rowFor(type: $type);
			self::assertNotNull($row, $type);
			self::assertSame($replacement, $row['replacement'], $type);
		}

		self::assertArrayNotHasKey('translation', (array)$map->rowFor(type: 'dossiq.requestDecision'));
		self::assertNull($map->rowFor(type: 'dossiq.setStatus'));
		self::assertNull($map->rowFor(type: 'dossiq.askPerson'));
		self::assertSame(['scheduleReminder', 'callWebhook'], array_keys($map->retiredActionTypes()));
	}//end testTheShippedTableMapsEveryRemovedNodeToItsOwner()

	/**
	 * A catalogue mail reads the case field, rewrites the template, and allows only addresses on the case.
	 *
	 * @return void
	 */
	public function testACatalogueMailBecomesASendEmailStep(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::EMAIL_ACTION,
			config: [
				'recipientRef' => 'indiener',
				'subjectTemplate' => 'Zaak {{case.identifier}}',
				'bodyTemplate' => 'Beste {{ case.title }},{{nope}} klaar.',
			]
		);

		self::assertSame(
			[
				[
					'type' => 'openregister.send-email',
					'config' => [
						'recipients' => ['{{ indiener }}'],
						'externalRecipients' => 'object',
						'subject' => 'Zaak {{ identifier }}',
						'body' => 'Beste {{ title }}, klaar.',
					],
				],
			],
			$steps
		);
	}//end testACatalogueMailBecomesASendEmailStep()

	/**
	 * A literal `email:` reference becomes the address itself.
	 *
	 * @return void
	 */
	public function testALiteralAddressIsCarriedAsAnAddress(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::EMAIL_ACTION,
			config: ['recipientRef' => 'email:loket@example.org', 'bodyTemplate' => 'Hallo']
		);

		self::assertSame(['loket@example.org'], $steps[0]['config']['recipients']);
	}//end testALiteralAddressIsCarriedAsAnAddress()

	/**
	 * A nested case path has no equivalent in the messaging syntax, so it is refused.
	 *
	 * @return void
	 */
	public function testANestedPathInAMailIsRefused(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('{{case.indiener.naam}}');

		$this->translator()->translate(
			translation: RetiredNodeMap::EMAIL_ACTION,
			config: ['recipientRef' => 'indiener', 'bodyTemplate' => 'Beste {{case.indiener.naam}}']
		);
	}//end testANestedPathInAMailIsRefused()

	/**
	 * A transition mail that named a stored template cannot be carried.
	 *
	 * @return void
	 */
	public function testATransitionMailWithAStoredTemplateIsRefused(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('stored email template "tpl-1"');

		$this->translator()->translate(
			translation: RetiredNodeMap::EMAIL_TRANSITION,
			config: ['to' => 'a@example.org', 'template' => 'tpl-1']
		);
	}//end testATransitionMailWithAStoredTemplateIsRefused()

	/**
	 * A transition mail keeps its literal subject and body.
	 *
	 * @return void
	 */
	public function testATransitionMailKeepsItsTextAsWritten(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::EMAIL_TRANSITION,
			config: ['to' => 'a@example.org', 'subject' => 'Status', 'body' => 'Uw zaak is verder.']
		);

		self::assertSame(
			['recipients' => ['a@example.org'], 'externalRecipients' => 'object', 'subject' => 'Status', 'body' => 'Uw zaak is verder.'],
			$steps[0]['config']
		);
	}//end testATransitionMailKeepsItsTextAsWritten()

	/**
	 * A role notification reads both role fields and gets the notifier's wording when it had none.
	 *
	 * @return void
	 */
	public function testARoleNotificationReadsBothRoleFields(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::NOTIFY_ROLE,
			config: ['roleSlug' => 'behandelaar', 'messageTemplate' => '']
		);

		self::assertSame('openregister.send-notification', $steps[0]['type']);
		self::assertSame(['{{ behandelaar }}', '{{ behandelaarMembers }}'], $steps[0]['config']['recipients']);
		self::assertSame('A case you handle needs your attention', $steps[0]['config']['title']);
		self::assertSame('Open the case to see what to do next.', $steps[0]['config']['message']);
	}//end testARoleNotificationReadsBothRoleFields()

	/**
	 * A transition notification without a user goes to the assignee, and names the transition when it knows it.
	 *
	 * @return void
	 */
	public function testATransitionNotificationFallsBackToTheAssignee(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::NOTIFY_TRANSITION,
			config: ['roleName' => 'Bezwaarmaker', 'message' => 'Uw bezwaar is afgehandeld', 'transitionLabel' => 'Afronden']
		);

		self::assertSame(
			[
				'recipients' => ['{{ assignee }}'],
				'title' => 'A case you handle changed status: Afronden',
				'message' => 'Uw bezwaar is afgehandeld',
			],
			$steps[0]['config']
		);
	}//end testATransitionNotificationFallsBackToTheAssignee()

	/**
	 * Setting a field puts it on the item and then writes only that field to the stored case.
	 *
	 * @return void
	 */
	public function testSetFieldBecomesASetAndAnObjectWrite(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::SET_FIELD,
			config: ['field' => 'archiveStatus', 'value' => 'gearchiveerd']
		);

		self::assertSame(['openregister.set-fields', 'openregister.object-write'], array_column($steps, 'type'));
		self::assertSame(['set' => ['archiveStatus' => 'gearchiveerd']], $steps[0]['config']);
		self::assertSame(
			[
				'register' => '12',
				'schema' => '34',
				'operation' => 'update',
				'match' => ['@self.uuid' => '{{ id }}'],
				'fields' => ['archiveStatus' => '{{ archiveStatus }}'],
				'output' => 'actionResult',
			],
			$steps[1]['config']
		);
	}//end testSetFieldBecomesASetAndAnObjectWrite()

	/**
	 * `__now__` becomes OpenRegister's `now` expression.
	 *
	 * @return void
	 */
	public function testTheNowMacroBecomesTheNowExpression(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::SET_FIELD,
			config: ['field' => 'closedAt', 'value' => '__now__']
		);

		self::assertSame(['compute' => ['closedAt' => ['now' => []]]], $steps[0]['config']);
	}//end testTheNowMacroBecomesTheNowExpression()

	/**
	 * Without a configured case schema there is nothing to write to, so the step is refused.
	 *
	 * @return void
	 */
	public function testSetFieldWithoutACaseSchemaIsRefused(): void {
		$this->expectException(UnmappableStep::class);

		$this->translator(configured: false)->translate(
			translation: RetiredNodeMap::SET_FIELD,
			config: ['field' => 'x', 'value' => 'y']
		);
	}//end testSetFieldWithoutACaseSchemaIsRefused()

	/**
	 * A decision carries the stored table inline and writes the mapped outputs to the case.
	 *
	 * @return void
	 */
	public function testADecisionCarriesItsTableAndWritesItsOutputs(): void {
		$table = [
			'id' => 'uuid-1',
			'@self' => ['id' => 'uuid-1'],
			'key' => 'toets',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [['name' => 'bedrag']],
			'outputs' => [['name' => 'uitkomst'], ['name' => 'reden']],
			'rules' => [],
		];

		$steps = $this->translator(table: $table)->translate(
			translation: RetiredNodeMap::DECISION,
			config: ['decisionKey' => 'toets', 'outputMapping' => ['uitkomst' => 'besluit']]
		);

		self::assertSame(['openregister.decision-table', 'openregister.object-write'], array_column($steps, 'type'));
		self::assertArrayNotHasKey('id', $steps[0]['config']['table']);
		self::assertArrayNotHasKey('@self', $steps[0]['config']['table']);
		self::assertSame('toets', $steps[0]['config']['table']['key']);
		self::assertSame(['uitkomst' => 'besluit'], $steps[0]['config']['outputMapping']);
		self::assertSame(['besluit' => '{{ besluit }}', 'reden' => '{{ reden }}'], $steps[1]['config']['fields']);
	}//end testADecisionCarriesItsTableAndWritesItsOutputs()

	/**
	 * A decision naming a table that does not exist is refused.
	 *
	 * @return void
	 */
	public function testADecisionWithoutItsTableIsRefused(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('no decision table has the key "weg"');

		$this->translator(table: null)->translate(translation: RetiredNodeMap::DECISION, config: ['decisionKey' => 'weg']);
	}//end testADecisionWithoutItsTableIsRefused()

	/**
	 * A merge into a field renders into that field and stores no file.
	 *
	 * @return void
	 */
	public function testAFieldMergeWritesTheFieldAndStoresNoFile(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::DOCUMENT_MERGE,
			config: [
				'templateSlug' => 'besluit',
				'template' => 'Besluit {{case.title}}: {{case.commissieBesluit.decision}}',
				'targetField' => 'besluitDocument',
			]
		);

		self::assertSame(
			[
				[
					'type' => 'filinq.generate-document',
					'config' => [
						'template' => 'Besluit {{ item.title }}: {{ item.commissieBesluit.decision }}',
						'storeFile' => false,
						'targetField' => 'besluitDocument',
						'requestingApp' => 'dossiq',
					],
				],
			],
			$steps
		);
	}//end testAFieldMergeWritesTheFieldAndStoresNoFile()

	/**
	 * A created document inlines its merge fields and asks dossiq to file it under its type.
	 *
	 * @return void
	 */
	public function testACreatedDocumentIsFiledUnderItsType(): void {
		$steps = $this->translator()->translate(
			translation: RetiredNodeMap::DOCUMENT_CREATE,
			config: [
				'templateSlug' => 'Aan {{case.title}}: {{case.mergeFields.besluit}}',
				'outputName' => 'besluit-{{case.identifier}}.md',
				'mergeFields' => ['besluit' => 'toegekend op {{case.datum}}'],
				'documentType' => 'type-uuid',
			]
		);

		$config = $steps[0]['config'];
		self::assertSame('Aan {{ item.title }}: toegekend op {{ item.datum }}', $config['template']);
		self::assertSame('besluit-{{ item.identifier }}', $config['filename']);
		self::assertTrue($config['storeFile']);
		self::assertSame('dossiq', $config['requestingApp']);
		self::assertSame(['informatieobjecttype' => 'type-uuid', 'direction' => 'outgoing', 'addressees' => 'case'], $config['metadata']);
	}//end testACreatedDocumentIsFiledUnderItsType()

	/**
	 * Text dossiq printed but Twig would run is refused.
	 *
	 * @return void
	 */
	public function testTwigSyntaxInADocumentIsRefused(): void {
		$this->expectException(UnmappableStep::class);

		$this->translator()->translate(
			translation: RetiredNodeMap::DOCUMENT_MERGE,
			config: ['template' => '{% if x %}', 'targetField' => 'f']
		);
	}//end testTwigSyntaxInADocumentIsRefused()

	/**
	 * A webhook has no Integriq equivalent, and the refusal names the host.
	 *
	 * @return void
	 */
	public function testAWebhookIsRefusedNamingItsHost(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('hooks.example.org');

		$this->translator()->translate(
			translation: RetiredNodeMap::WEBHOOK,
			config: ['url' => 'https://hooks.example.org/case?token=secret']
		);
	}//end testAWebhookIsRefusedNamingItsHost()

	/**
	 * A step that becomes two keeps its id for the first and hands its exits to the second.
	 *
	 * @return void
	 */
	public function testTheRewriterChainsATwoStepTranslation(): void {
		$rewriter = new RetiredNodeRewriter(new RetiredNodeMap(), $this->translator());

		$result = $rewriter->rewrite(
			nodes: [
				['id' => 'start', 'type' => 'openregister.trigger-manual'],
				['id' => 'archive', 'type' => 'dossiq.setField', 'config' => ['field' => 'archiveStatus', 'value' => 'x']],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			edges: [
				['id' => 'e1', 'from' => 'start', 'to' => 'archive'],
				['id' => 'e2', 'from' => ['archive'], 'to' => ['end']],
			]
		);

		self::assertSame(['start', 'archive', 'archive--2', 'end'], array_column($result['nodes'], 'id'));
		self::assertSame(
			[
				['id' => 'e1', 'from' => 'start', 'to' => 'archive'],
				['id' => 'e2', 'from' => ['archive--2'], 'to' => ['end']],
				['id' => 'archive-archive--2', 'from' => 'archive', 'to' => 'archive--2'],
			],
			$result['edges']
		);
		self::assertSame('replaced', $result['changes'][0]['outcome']);
		self::assertSame('openregister.set-fields + openregister.object-write', $result['changes'][0]['replacement']);

		// A second pass finds nothing retired.
		$again = $rewriter->rewrite(nodes: $result['nodes'], edges: $result['edges']);
		self::assertSame([], $again['changes']);
	}//end testTheRewriterChainsATwoStepTranslation()

	/**
	 * A refused step stays in the graph, untouched, and says why.
	 *
	 * @return void
	 */
	public function testTheRewriterLeavesAnUnmappableStepInPlace(): void {
		$node = ['id' => 'hook', 'type' => 'dossiq.webhook', 'config' => ['url' => 'https://x.example.org/']];
		$edges = [['id' => 'e', 'from' => 'hook', 'to' => 'end']];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap(), $this->translator()))->rewrite(
			nodes: [$node, ['id' => 'end', 'type' => 'openregister.end']],
			edges: $edges
		);

		self::assertSame($node, $result['nodes'][0]);
		self::assertSame($edges, $result['edges']);
		self::assertSame('unmappable', $result['changes'][0]['outcome']);
		self::assertStringContainsString('x.example.org', $result['changes'][0]['reason']);
	}//end testTheRewriterLeavesAnUnmappableStepInPlace()

	/**
	 * The store that cannot be reached is a refusal, not a guess.
	 *
	 * @return void
	 */
	public function testAnUnreachableTableStoreIsARefusal(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$syntax = new RetiredTemplateSyntax();
		$translator = new RetiredNodeTranslator(
			$this->createMock(SettingsService::class),
			$container,
			$this->createMock(IL10N::class),
			$syntax,
			new RetiredDocumentSteps($syntax)
		);

		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('could not be read');

		$translator->translate(translation: RetiredNodeMap::DECISION, config: ['decisionKey' => 'toets']);
	}//end testAnUnreachableTableStoreIsARefusal()
}//end class
