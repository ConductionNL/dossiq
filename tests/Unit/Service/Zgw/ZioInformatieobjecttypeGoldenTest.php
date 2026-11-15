<?php

/**
 * Golden test for the zrc-017 rule of ZgwZrcZaakinformatieobjectRules: when a
 * zaakinformatieobject is refused because its document type does not belong to
 * the zaaktype, and what is looked up on the way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZgwZrcZaakinformatieobjectRules;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class ZioInformatieobjecttypeGoldenTest extends TestCase {

	private const DOC = '11111111-1111-4111-8111-111111111111';

	private const ZAAK = '22222222-2222-4222-8222-222222222222';

	private const ZT = '33333333-3333-4333-8333-333333333333';

	private const IOT = '44444444-4444-4444-8444-444444444444';

	private array $asked = [];

	private function validate(array $objects, mixed $found, string $ziotSchema = 'ziot', string $caseUrl = 'http://x/zaken/' . self::ZAAK, string $ioUrl = 'http://x/documenten/' . self::DOC): ?array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'zaaktype_informatieobjecttype_schema' => $ziotSchema,
				'register' => 'reg',
				default => 'schema:' . $key,
			}
		);
		$rules = new ZgwZrcZaakinformatieobjectRules(logger: $this->createMock(LoggerInterface::class), settingsService: $settings, fieldValidator: new FieldValidator());
		$asked = &$this->asked;
		$objectService = new class ($objects, $found, $asked) {
			public function __construct(private array $objects, private mixed $found, private array &$asked) {
			}

			public function find(string $id, string $register, string $schema): mixed {
				$this->asked[] = ['find', $id, $schema];
				return ($this->objects[$id] ?? null);
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				$this->asked[] = ['query', $requestParams, $register, $schema];
				return ['q' => $requestParams];
			}

			public function searchObjectsPaginated(array $query): array {
				$this->asked[] = ['search'];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return ['results' => $this->found];
			}
		};
		$rules->setContext(objectService: $objectService, mappingConfig: ['sourceRegister' => '1']);

		return (new ReflectionMethod($rules, 'validateZioInformatieobjecttype'))->invoke($rules, $caseUrl, $ioUrl);
	}//end validate()

	public function testEveryAnswer(): void {
		$objects = [
			self::DOC => ['documentType' => 'http://x/informatieobjecttypen/' . self::IOT],
			self::ZAAK => ['caseType' => 'http://x/zaaktypen/' . self::ZT],
		];

		$refusal = $this->validate($objects, []);
		$this->assertSame(
			[
				['find', self::DOC, 'schema:document_schema'],
				['find', self::ZAAK, 'schema:case_schema'],
				['query', ['caseType' => self::ZT, 'informatieobjecttype' => self::IOT, '_limit' => 1], 'reg', 'ziot'],
				['search'],
			],
			$this->asked
		);
		$this->assertSame(400, $refusal['status']);
		$this->assertSame('Het informatieobjecttype van het informatieobject hoort niet bij het zaaktype van de zaak.', $refusal['detail']);
		$this->assertSame(
			[['name' => 'nonFieldErrors', 'code' => 'missing-zaaktype-informatieobjecttype-relation', 'reason' => 'Het informatieobjecttype van het informatieobject hoort niet bij het zaaktype van de zaak.']],
			$refusal['invalidParams']
		);

		$this->assertNull($this->validate($objects, [['id' => 'link']]));
		$this->assertNull($this->validate($objects, new RuntimeException('down')));
		$this->assertNull($this->validate($objects, [], ziotSchema: ''));
		$this->assertNull($this->validate([self::ZAAK => $objects[self::ZAAK]], []));
		$this->assertNull($this->validate([self::DOC => ['documentType' => '']] + $objects, []));
		$this->assertNull($this->validate([self::DOC => ['documentType' => 'geen-uuid']] + $objects, []));
		$this->assertNull($this->validate([self::DOC => $objects[self::DOC], self::ZAAK => ['caseType' => 'x']], []));
		$this->assertNull($this->validate([self::DOC => $objects[self::DOC]], []));
		$this->assertNull($this->validate($objects, [], ioUrl: 'http://x/documenten/geen'));
		$this->assertNull($this->validate($objects, [], caseUrl: 'http://x/zaken/geen'));
	}//end testEveryAnswer()
}//end class
