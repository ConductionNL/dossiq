<?php

/**
 * Characterisation tests for the besluit identificatie uniqueness lookup (brc-002).
 *
 * Pins what ZgwBrcRulesService asks the store for when a besluit carries an
 * identificatie, how the total is read, when nothing is asked at all, and that
 * a failed lookup is logged and lets the besluit through.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwBrcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the brc-002 lookup.
 */
class ZgwBrcUniquenessLookupTest extends TestCase {
	/**
	 * The search parameters asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The rules over a store answering the given search result, or throwing.
	 *
	 * @param array<string, mixed>|null $answer        The search result, or null to throw.
	 * @param array<string, mixed>      $mappingConfig The mapping config.
	 *
	 * @return ZgwBrcRulesService
	 */
	private function rules(?array $answer, array $mappingConfig = ['sourceRegister' => '1', 'sourceSchema' => '2']): ZgwBrcRulesService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('schema-x');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$service = new ZgwBrcRulesService(logger: $logger, settingsService: $settings, fieldValidator: new FieldValidator());

		$store = new class ($answer, $this->asked) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null        $answer The search result, or null to throw.
			 * @param array<int, array<string, mixed>> $asked  Where the asked parameters go.
			 */
			public function __construct(private ?array $answer, private array &$asked) {
			}//end __construct()

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null Nothing is stored.
			 */
			public function find(string $id, string $register, string $schema): ?array {
				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $requestParams The parameters.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 *
			 * @return array<string, mixed> The query.
			 */
			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				$this->asked[] = $requestParams + ['@register' => $register, '@schema' => $schema];
				return ['q' => $requestParams];
			}//end buildSearchQuery()

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed> The result.
			 */
			public function searchObjectsPaginated(array $query): array {
				if ($this->answer === null) {
					throw new RuntimeException('search down');
				}

				return $this->answer;
			}//end searchObjectsPaginated()
		};
		$service->setContext(objectService: $store, mappingConfig: $mappingConfig);

		return $service;
	}//end rules()

	/**
	 * The identificatie is looked up by title, with the organisation when one is given.
	 *
	 * @return void
	 */
	public function testTheLookupAsksByTitleAndOrganisation(): void {
		$this->rules(['total' => 0])->rulesBesluitenCreate(body: ['identificatie' => 'B-1', 'verantwoordelijkeOrganisatie' => '123456789']);
		$this->rules(['total' => 0])->rulesBesluitenCreate(body: ['identificatie' => 'B-2']);

		self::assertSame(
			[
				['title' => 'B-1', 'responsibleOrganisation' => '123456789', '@register' => '1', '@schema' => '2'],
				['title' => 'B-2', '@register' => '1', '@schema' => '2'],
			],
			$this->asked
		);
	}//end testTheLookupAsksByTitleAndOrganisation()

	/**
	 * Without a total the results are counted; a taken identificatie is refused in full.
	 *
	 * @return void
	 */
	public function testTheResultsAreCountedWithoutATotal(): void {
		$taken = $this->rules(['results' => [['id' => 'x']]])->rulesBesluitenCreate(body: ['identificatie' => 'B-1']);
		$free = $this->rules(['results' => []])->rulesBesluitenCreate(body: ['identificatie' => 'B-1']);

		self::assertFalse($taken['valid']);
		self::assertSame(400, $taken['status']);
		self::assertSame('De combinatie van verantwoordelijke_organisatie en identificatie is niet uniek.', $taken['detail']);
		self::assertSame(
			[
				'name' => 'identificatie',
				'code' => 'identificatie-niet-uniek',
				'reason' => 'De combinatie van verantwoordelijke_organisatie en identificatie bestaat al.',
			],
			$taken['invalidParams'][0]
		);
		self::assertTrue($free['valid']);
	}//end testTheResultsAreCountedWithoutATotal()

	/**
	 * No register or schema in the mapping asks nothing.
	 *
	 * @return void
	 */
	public function testNoSourceSchemaAsksNothing(): void {
		$result = $this->rules(['total' => 5], ['sourceRegister' => '1'])->rulesBesluitenCreate(body: ['identificatie' => 'B-1']);

		self::assertTrue($result['valid']);
		self::assertSame([], $this->asked);
	}//end testNoSourceSchemaAsksNothing()

	/**
	 * A failed lookup is logged and lets the besluit through.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsLoggedAndPasses(): void {
		$result = $this->rules(null)->rulesBesluitenCreate(body: ['identificatie' => 'B-1']);

		self::assertTrue($result['valid']);
		self::assertSame(['brc-002: Could not check besluit identificatie uniqueness: search down'], $this->warnings);
	}//end testAFailedLookupIsLoggedAndPasses()
}//end class
