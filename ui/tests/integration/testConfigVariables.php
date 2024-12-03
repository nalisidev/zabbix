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

	const VALID_NAMES = [
		'var',
		'var123',
		'_var123',
		'a',
		'_'
	];

	const INVALID_NAMES = [
		'123var', // starts with a digit
		'var-123', // contains a hyphen
		'var 123', // contains a space
	];

	private static $include_files = [
		self::COMPONENT_AGENT => PHPUNIT_CONFIG_DIR . self::COMPONENT_AGENT . '_usrprm_with_vars.conf',
		self::COMPONENT_AGENT2 => PHPUNIT_CONFIG_DIR . self::COMPONENT_AGENT2 . '_usrprm_with_vars.conf'
	];

	private static $proxyids = [];
	private static $hostids = [];
	private static $itemids = [];
	private static $envvars = [];

	private static function putenv($name, $value) {
		putenv($name . '=' . $value);
		self::$envvars[] = $name;
	}

	public static function prepareTestEnv(): void {
		self::putenv('StartPollers', self::START_POLLERS);
	}

	public static function cleanupTestEnv(): void {
		CDataHelper::call('history.clear', self::$itemids);
		CDataHelper::call('item.delete', self::$itemids);
		CDataHelper::call('host.delete', self::$hostids);
		CDataHelper::call('proxy.delete', self::$proxyids);

		foreach (self::$include_files as $file) {
			if (file_exists($file)) {
				unlink($file);
			}
		}
		foreach (self::$envvars as $var) {
			putenv($var);
		}
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
					]
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
					]
				]
			]
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
	public function configurationProviderWorkerCount() {
		return [
			self::COMPONENT_SERVER => [
				'StartPollers' => '${StartPollers}'
			],
			self::COMPONENT_PROXY => [
				'Hostname' => self::PROXY_NAME,
				'Server' => '127.0.0.1:'.self::getConfigurationValue(self::COMPONENT_SERVER, 'ListenPort'),
				'StartPollers' => '${StartPollers}'
			]
		];
	}

	/**
	 * Check the number of pollers set with variable in configuration file
	 *
	 * @configurationDataProvider configurationProviderWorkerCount
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

	/**
	 * Component configuration provider.
	 *
	 * @return array
	 */
	public function configurationProviderVarNames() {
		foreach (self::VALID_NAMES as $idx => $var_name) {
			// 'valid_usrprm0,echo valid_usrprm var123';
			$var_val = 'valid_usrprm' . $idx . ',echo valid_usrprm ' . $var_name;
			self::putenv($var_name, $var_val);
		}

		// Currently multiple identical configuration parameters are not allowed by the test environment,
		// so use put them into an file and include it.
		foreach ([self::COMPONENT_AGENT, self::COMPONENT_AGENT2] as $component) {
			$filename = self::$include_files[$component];

			$data = "";
			foreach (self::VALID_NAMES as $name) {
				$data .= 'UserParameter=' . '${' . $name . '}' . PHP_EOL;
			}

			if (file_put_contents($filename, $data) === false) {
				throw new Exception('Failed to create include configuration file for %s', $component);
			}
		}

		return [
			self::COMPONENT_AGENT => [
				'Include' => self::$include_files[self::COMPONENT_AGENT]
			],
			self::COMPONENT_AGENT2 => [
				'Include' => self::$include_files[self::COMPONENT_AGENT2]
			]
		];
	}

	/**
	 * Test valid variable names
	 *
	 * @configurationDataProvider configurationProviderVarNames
	 * @required-components agent,agent2
	 */
	public function testConfigTestOption_VariableNames() {

		foreach ([self::COMPONENT_AGENT, self::COMPONENT_AGENT2] as $component) {
			foreach (self::VALID_NAMES as $idx => $var_name) {
				$usrprm_key = 'valid_usrprm' . $idx;
				$expected_output = 'valid_usrprm ' . $var_name . PHP_EOL;

				$port =	$this->getConfigurationValue($component, 'ListenPort');
				$output = shell_exec(PHPUNIT_BASEDIR . '/bin/zabbix_get -s 127.0.0.1 -p ' . $port .
					' -k ' . $usrprm_key . ' -t 7');

				$this->assertNotNull($output);
				$this->assertNotFalse($output);
				$this->assertEquals($expected_output, $output);
			}
		}
	}

	/**
	 * Test invalid variable names
	 */
	public function testConfigTestOption_InvalidVariableNames() {
		foreach (self::INVALID_NAMES as $idx => $var_name) {
			// 'invalid_usrprm0,echo invalid_usrprm var-123';
			$var_val = 'invalid_usrprm' . $idx . ',echo invalid_usrprm ' . $var_name;
			self::putenv($var_name, $var_val);
		}

		$def_config = self::getDefaultComponentConfiguration();

		foreach ([self::COMPONENT_AGENT, self::COMPONENT_AGENT2] as $component) {
			foreach (self::INVALID_NAMES as $name) {
				$config = [
					$component => [
						'UserParameter' => '${' . $name . '}'
					]
				];

				$config[$component] = array_merge($config[$component], $def_config[$component]);
				self::prepareComponentConfiguration($component, $config);

				$config_path = PHPUNIT_CONFIG_DIR.'zabbix_'.$component.'.conf';
				if (!file_exists($config_path)) {
					throw new Exception('There is no configuration file for component "'.$component.'".');
				}
				$background = ($component === self::COMPONENT_AGENT2);
				$bin_path = PHPUNIT_BINARY_DIR.'zabbix_'.$component;

				self::clearLog($component);
				self::executeCommand($bin_path, ['-c', $config_path], $background);
				sleep(1); // wait until the component starts and stops
				$this->stopComponent($component); // for safety

				if ($component === self::COMPONENT_AGENT) {
					$needle = 'cannot load user parameters: user parameter "${' . $name . '}": not comma-separated';
				} else {
					$needle = 'Cannot initialize user parameters: cannot add user parameter "${' . $name . '}": not comma-separated';
				}

				$log = CLogHelper::readLog(self::getLogPath($component), false);
				$err_msg = 'String "' . $needle . '" is not found in log file for component "' . $component . '".';
				$this->assertTrue(str_contains($log, $needle), $err_msg);
			}
		}
	}
}
