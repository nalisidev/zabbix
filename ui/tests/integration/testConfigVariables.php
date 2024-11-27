<?php
/*
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/

require_once dirname(__FILE__).'/../include/CIntegrationTest.php';

/**
 * Test variables in configuration files
 *
 * @onBefore prepareTestEnv
 * @onAfter cleanupTestEnv
 *
 */
class testConfigVariables extends CIntegrationTest {
	const SERVER_NAME = 'server';
	const PROXY_NAME = 'proxy';
	const STATS_ITEM_NAME = 'stats item';
	const STATS_ITEM_KEY = 'zabbix[stats,,]';
	const START_POLLERS = 12;

	private static $proxyids = [];
	private static $hostids = [];
	private static $itemids = [];


	public static function prepareTestEnv(): void {
		putenv('StartPollers=' . self::START_POLLERS);
	}

	public static function cleanupTestEnv(): void {
		putenv('StartPollers');
		CDataHelper::call('history.clear', self::$itemids);
		CDataHelper::call('item.delete', self::$itemids);
		CDataHelper::call('host.delete', self::$hostids);
		CDataHelper::call('proxy.delete', self::$proxyids);
	}

	/**
	 * @inheritdoc
	 */
	public function prepareData() {
		// Create proxy
		CDataHelper::call('proxy.create', [
			'name' => self::PROXY_NAME,
			'operating_mode' => PROXY_OPERATING_MODE_ACTIVE
		]);

		self::$proxyids = CDataHelper::getIds('name');

		// Create hosts for monitoring server and proxy using internal checks
		$interfaces = [
			'type' => 1,
			'main' => 1,
			'useip' => 1,
			'ip' => '127.0.0.1',
			'dns' => '',
			'port' => $this->getConfigurationValue(self::COMPONENT_AGENT, 'ListenPort')
		];

		$groups = ['groupid' => 4];

		$result = CDataHelper::createHosts([
			[
				'host' => self::SERVER_NAME,
				'interfaces' => $interfaces,
				'groups' => $groups,
				'monitored_by' => ZBX_MONITORED_BY_SERVER,
				'status' => HOST_STATUS_MONITORED,
				'items' => [
					[
						'name' => self::STATS_ITEM_NAME,
						'key_' => self::STATS_ITEM_KEY,
						'type' => ITEM_TYPE_INTERNAL,
						'value_type' => ITEM_VALUE_TYPE_TEXT,
						'delay' => '1s'
					],
				]
			],
			[
				'host' => self::PROXY_NAME,
				'interfaces' => $interfaces,
				'groups' => $groups,
				'monitored_by' => ZBX_MONITORED_BY_PROXY,
				'proxyid' => self::$proxyids[self::PROXY_NAME],
				'status' => HOST_STATUS_MONITORED,
				'items' => [
					[
						'name' => self::STATS_ITEM_NAME,
						'key_' => self::STATS_ITEM_KEY,
						'type' => ITEM_TYPE_INTERNAL,
						'value_type' => ITEM_VALUE_TYPE_TEXT,
						'delay' => '1s'
					],
				]
			],
		]);

		self::$hostids = $result['hostids'];
		self::$itemids = $result['itemids'];

		return true;
	}

	/**
	 * Component configuration provider.
	 *
	 * @return array
	 */
	public function configurationProvider() {
		return [
			self::COMPONENT_SERVER => [
				'StartPollers' => '${StartPollers}',
			],
			self::COMPONENT_PROXY => [
				'Hostname' => self::PROXY_NAME,
				'Server' => '127.0.0.1:'.self::getConfigurationValue(self::COMPONENT_SERVER, 'ListenPort'),
				'StartPollers' => '${StartPollers}',
			],
		];
	}


	/**
	 * Check the number of pollers set with variable in configuration file
	 *
	 * @configurationDataProvider configurationProvider
	 * @required-components server, proxy
	 */
	public function testConfigTestOption_WorkerCount() {
		foreach([self::SERVER_NAME, self::PROXY_NAME] as $component) {
			$maxAttempts = 10;
			$attempt = 0;
			$success = false;

			while ($attempt < $maxAttempts && !$success) {
				$attempt++;

				$response = $this->callUntilDataIsPresent('history.get', [
					'itemids' => self::$itemids[$component . ':' . self::STATS_ITEM_KEY],
					'history' => ITEM_VALUE_TYPE_TEXT,
					"sortfield" => "clock",
					"sortorder" => "DESC",
					"limit" => 1
				]);

				$this->assertArrayHasKey('result', $response);
				$this->assertEquals(1, count($response['result']));
				$this->assertArrayHasKey('value', $response['result'][0]);
				$stats = json_decode($response['result'][0]['value'], true);

				if (!isset($stats['data']['process']['poller']['count'])) {
					sleep(1);
					continue;
				}

				$poller_count = $stats['data']['process']['poller']['count'];
				$this->assertEquals(
					self::START_POLLERS,
					$poller_count,
					'Actual number of pollers does not match the configured one while testing component ' . $component);

				$success = true;
			}

			if (!$success) {
				$this->fail('Failed to get poller count during the max number of attempts for component ' . $component);
			}
		}
	}

}
