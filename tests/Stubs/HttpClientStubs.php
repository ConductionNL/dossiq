<?php

/**
 * Minimal OCP HTTP client interface stubs for unit tests.
 *
 * The vendored `nextcloud/ocp` package shipped in this app does not include the
 * `OCP\Http\Client` namespace, so services that depend on `IClientService`
 * (PublicationService, MandaatValidationService) cannot be mocked without these
 * stub interfaces. They mirror the real Nextcloud signatures closely enough for
 * createMock() to build a usable double.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCP\AppFramework {
	if (class_exists(\OCP\AppFramework\App::class) === false) {
		/**
		 * Minimal App stub so OCA\Dossiq\AppInfo\Application (which extends it
		 * only to expose APP_ID) can be autoloaded in the unit-test runtime.
		 */
		class App {
			/**
			 * Constructor.
			 *
			 * @param string $appName The app id.
			 * @param array $params Optional bootstrap params.
			 */
			public function __construct(string $appName, array $params = []) {
			}//end __construct()
		}//end class
	}
}

namespace OCP\AppFramework\Bootstrap {
	if (interface_exists(\OCP\AppFramework\Bootstrap\IRegistrationContext::class) === false) {
		/**
		 * Minimal IRegistrationContext stub.
		 */
		interface IRegistrationContext {
		}//end interface
	}

	if (interface_exists(\OCP\AppFramework\Bootstrap\IBootContext::class) === false) {
		/**
		 * Minimal IBootContext stub.
		 */
		interface IBootContext {
		}//end interface
	}

	if (interface_exists(\OCP\AppFramework\Bootstrap\IBootstrap::class) === false) {
		/**
		 * Minimal IBootstrap stub.
		 */
		interface IBootstrap {
			/**
			 * Register services.
			 *
			 * @param IRegistrationContext $context The registration context.
			 *
			 * @return void
			 */
			public function register(IRegistrationContext $context): void;

			/**
			 * Boot the app.
			 *
			 * @param IBootContext $context The boot context.
			 *
			 * @return void
			 */
			public function boot(IBootContext $context): void;
		}//end interface
	}
}

namespace OCP\Http\Client {
	if (interface_exists(\OCP\Http\Client\IResponse::class) === false) {
		/**
		 * Minimal IResponse stub.
		 */
		interface IResponse {
			/**
			 * Get the response body.
			 *
			 * @return string|resource
			 */
			public function getBody();

			/**
			 * Get the HTTP status code.
			 *
			 * @return int
			 */
			public function getStatusCode(): int;
		}//end interface
	}

	if (interface_exists(\OCP\Http\Client\IClient::class) === false) {
		/**
		 * Minimal IClient stub.
		 */
		interface IClient {
			/**
			 * Issue a GET request.
			 *
			 * @param string $uri The URI.
			 * @param array $options The request options.
			 *
			 * @return IResponse
			 */
			public function get(string $uri, array $options = []): IResponse;

			/**
			 * Issue a POST request.
			 *
			 * @param string $uri The URI.
			 * @param array $options The request options.
			 *
			 * @return IResponse
			 */
			public function post(string $uri, array $options = []): IResponse;
		}//end interface
	}

	if (interface_exists(\OCP\Http\Client\IClientService::class) === false) {
		/**
		 * Minimal IClientService stub.
		 */
		interface IClientService {
			/**
			 * Create a new HTTP client.
			 *
			 * @return IClient
			 */
			public function newClient(): IClient;
		}//end interface
	}
}
