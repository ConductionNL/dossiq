<?php

/**
 * ZtcHelperFactory Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher;
use OCA\Dossiq\Service\Zgw\ZtcHelperFactory;
use OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver;
use OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each helper the factory builds is the real implementation, wired to the collaborator it was given.
 *
 * Wiring is proven by behaviour: the helper must ask the ZGW service it was handed
 * (or log on the logger it was handed) for the answer it returns.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcHelperFactory
 * @uses \OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver
 * @uses \OCA\Dossiq\Service\Zgw\ZtcCrossReferenceEnricher
 * @uses \OCA\Dossiq\Service\Zgw\ZtcRelatedTypeLookup
 * @uses \OCA\Dossiq\Service\Zgw\ZtcStoredUuidUrls
 * @uses \OCA\Dossiq\Service\Zgw\ZtcUrlValidityFilter
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class ZtcHelperFactoryTest extends TestCase {
	/**
	 * A ZGW service without OpenRegister, which must be asked exactly once.
	 *
	 * @return ZgwService
	 */
	private function zgwAskedOnce(): ZgwService {
		$zgw = $this->createMock(ZgwService::class);
		$zgw->expects($this->once())->method('getObjectService')->willReturn(null);
		$zgw->expects($this->never())->method('loadMappingConfig');

		return $zgw;
	}//end zgwAskedOnce()

	/**
	 * The type-name resolver looks an omschrijving up through the given service, and finds no store.
	 *
	 * @return void
	 */
	public function testTheTypeNameResolverAsksTheGivenService(): void {
		$zgw = $this->zgwAskedOnce();
		$zgw->expects($this->never())->method('updateCachedBodyField');

		$resolver = (new ZtcHelperFactory())->ztcTypeNameResolver(zgwService: $zgw);

		$this->assertInstanceOf(ZtcTypeNameResolver::class, $resolver);
		$resolver->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);
	}//end testTheTypeNameResolverAsksTheGivenService()

	/**
	 * The enricher reads through the given service, and without a store returns the object as it is.
	 *
	 * @return void
	 */
	public function testTheEnricherAsksTheGivenService(): void {
		$enricher = (new ZtcHelperFactory())->ztcCrossReferenceEnricher(zgwService: $this->zgwAskedOnce());

		$this->assertInstanceOf(ZtcCrossReferenceEnricher::class, $enricher);
		$this->assertSame(['uuid' => 'zt-1'], $enricher->enrich(resource: 'zaaktypen', data: ['uuid' => 'zt-1'], baseUrl: 'https://nc/catalogi/v1'));
	}//end testTheEnricherAsksTheGivenService()

	/**
	 * The validity filter reads through the given service, and without a store judges nothing.
	 *
	 * @return void
	 */
	public function testTheValidityFilterAsksTheGivenService(): void {
		$filter = (new ZtcHelperFactory())->ztcUrlValidityFilter(zgwService: $this->zgwAskedOnce());
		$data = ['informatieobjecttypen' => ['https://nc/catalogi/v1/informatieobjecttypen/x']];

		$this->assertInstanceOf(ZtcUrlValidityFilter::class, $filter);
		$this->assertSame(
			$data,
			$filter->filter(fieldConfigs: ['informatieobjecttypen' => ['schemaKey' => 'document_type_schema', 'nested' => false]], data: $data, today: '2026-06-15')
		);
	}//end testTheValidityFilterAsksTheGivenService()

	/**
	 * The rule-result builder logs on the given logger when a rule could not run.
	 *
	 * @return void
	 */
	public function testTheRuleResultsLogOnTheGivenLogger(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('could not be checked'),
			['rule' => 'zrc-007', 'object' => 'zaak z', 'cause' => 'down']
		);

		$results = (new ZtcHelperFactory())->zgwRuleResults(logger: $logger);

		$this->assertInstanceOf(ZgwRuleResults::class, $results);
		$this->assertSame(503, $results->notVerified(rule: 'zrc-007', object: 'zaak z', cause: 'down')['status']);
	}//end testTheRuleResultsLogOnTheGivenLogger()
}//end class
