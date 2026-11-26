<?php

namespace Yajra\Oci8\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;
use PDO;
use Yajra\Pdo\Oci8;

/**
 * @phpstan-type OracleConfig array{
 *     tns?: string|null, host?: string|list<string>|null, hostname?: string|null,
 *     port?: int|string|null, protocol?: string|null, database?: string|null, service_name?: string|null,
 *     username?: string|null, password?: string|null, charset?: string|null, driver?: string,
 *     schema?: string, prefix_schema?: string, max_name_len?: int|numeric-string|null,
 *     server_version?: scalar|null, load_balance?: string|null, service?: string,
 *     connect_timeout?: int|string|null, retry_count?: int|string|null, retry_delay?: int|string|null,
 *     transport_connect_timeout?: int|string|null, expire_time?: int|string|null,
 *     options?: array<array-key, mixed>, sessionVars?: array<string, scalar|null>,
 *     session?: array<string, scalar|null>, edition?: string, dynamic?: array{}|callable,
 *     skip_session_vars?: bool, ...
 * }
 */
class OracleConnector extends Connector implements ConnectorInterface
{
    /**
     * The default PDO connection options.
     *
     * @var array<int|string, mixed>
     */
    protected $options = [
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ];

    /**
     * Establish a database connection.
     *
     *
     * @param  OracleConfig  $config
     *
     * @throws \Exception
     */
    public function connect(array $config): PDO
    {
        $tns = ! empty($config['tns']) ? $config['tns'] : $this->getDsn($config);

        $options = $this->getOptions($config);

        if (defined('OCI_CRED_EXT') && Arr::get($options, 'session_mode') === OCI_CRED_EXT) {
            // External connections can only be used with user / and an empty password
            $config['username'] = '/';
            $config['password'] = null;
        }

        return $this->createConnection($tns, $config, $options);
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param  OracleConfig  $config
     */
    protected function getDsn(array $config): string
    {
        if (! empty($config['tns'])) {
            return $config['tns'];
        }

        // parse configuration
        $config = $this->parseConfig($config);

        // check multiple connections/host, comma delimiter
        $config = $this->checkMultipleHostDsn($config);

        // return generated tns
        return $config['tns'] ?? throw new \LogicException('Oracle DSN was not generated.');
    }

    /**
     * Parse configurations.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function parseConfig(array $config): array
    {
        $config = $this->setHost($config);
        $config['port'] ??= '1521';
        $config['protocol'] ??= 'TCP';
        $config = $this->setServiceId($config);
        $config = $this->setTNS($config);

        return $this->setCharset($config);
    }

    /**
     * Set host from config.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function setHost(array $config): array
    {
        $config['host'] ??= $config['hostname'] ?? '';

        return $config;
    }

    /**
     * Set service id from config.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function setServiceId(array $config): array
    {
        $config['service'] = empty($config['service_name'])
            ? 'SID = '.($config['database'] ?? '')
            : 'SERVICE_NAME = '.$config['service_name'];

        return $config;
    }

    /**
     * Set tns from config.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function setTNS(array $config): array
    {
        $descriptionOptions = $this->buildDescriptionOptions($config);

        $host = $config['host'] ?? '';
        $host = is_array($host) ? implode(',', $host) : $host;
        $protocol = $config['protocol'] ?? 'TCP';
        $port = $config['port'] ?? '1521';
        $service = $config['service'] ?? '';

        $config['tns'] = "(DESCRIPTION = (ADDRESS = (PROTOCOL = {$protocol})(HOST = {$host})(PORT = {$port})){$descriptionOptions} (CONNECT_DATA =({$service})))";

        return $config;
    }

    /**
     * Set charset from config.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function setCharset(array $config): array
    {
        if (! isset($config['charset'])) {
            $config['charset'] = 'AL32UTF8';
        }

        return $config;
    }

    /**
     * Set DSN host from config.
     *
     * @param  OracleConfig  $config
     * @return OracleConfig
     */
    protected function checkMultipleHostDsn(array $config): array
    {
        $hosts = $config['host'] ?? '';
        $hosts = is_array($hosts) ? $hosts : explode(',', $hosts);

        if (count($hosts) > 1) {
            $protocol = $config['protocol'] ?? 'TCP';
            $port = $config['port'] ?? '1521';
            $service = $config['service'] ?? '';
            $address = '';
            foreach ($hosts as $host) {
                $address .= '(ADDRESS = (PROTOCOL = '.$protocol.')(HOST = '.trim($host).')(PORT = '.$port.'))';
            }

            // backwards compatibility for users dont have this field in their php config
            $loadBalance = $config['load_balance'] ?? 'yes';
            $descriptionOptions = $this->buildDescriptionOptions($config);

            // create a tns with multiple address connection
            $config['tns'] = "(DESCRIPTION = {$address} (LOAD_BALANCE = {$loadBalance}) (FAILOVER = on){$descriptionOptions} (CONNECT_DATA = (SERVER = DEDICATED) ({$service})))";
        }

        return $config;
    }

    /**
     * Build optional Oracle connection descriptor parameters.
     *
     * @param  OracleConfig  $config
     */
    protected function buildDescriptionOptions(array $config): string
    {
        $supportedOptions = [
            'connect_timeout' => ['descriptor' => 'CONNECT_TIMEOUT', 'min_version' => '11g'],
            'retry_count' => ['descriptor' => 'RETRY_COUNT', 'min_version' => '12c'],
            'retry_delay' => ['descriptor' => 'RETRY_DELAY', 'min_version' => '12c'],
            'transport_connect_timeout' => ['descriptor' => 'TRANSPORT_CONNECT_TIMEOUT', 'min_version' => '12c'],
            'expire_time' => ['descriptor' => 'EXPIRE_TIME', 'min_version' => '19c'],
        ];

        $options = '';
        foreach ($supportedOptions as $configKey => $definition) {
            if (
                empty($config[$configKey])
                || version_compare($definition['min_version'], (string) ($config['server_version'] ?? '11g'), '>')
            ) {
                continue;
            }

            $options .= ' ('.$definition['descriptor'].' = '.trim((string) $config[$configKey]).')';
        }

        return $options;
    }

    /**
     * Create a new PDO connection.
     *
     * @param  string  $dsn
     * @param  OracleConfig  $config
     * @param  array<array-key, mixed>  $options
     *
     * @throws \Exception
     */
    public function createConnection($dsn, array $config, array $options): PDO|Oci8
    {
        // add fallback in case driver is not set, will use pdo instead
        if (! in_array($config['driver'] ?? null, ['oci8', 'pdo-via-oci8', 'oracle'])) {
            return parent::createConnection($dsn, $config, $options);
        }

        $config = $this->setCharset($config);
        $options['charset'] = $config['charset'] ?? 'AL32UTF8';

        return parent::createConnection($dsn, $config, $options);
    }

    /**
     * Create a new PDO connection instance.
     *
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array<array-key, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): Oci8
    {
        return new Oci8($dsn, $username, $password, $options);
    }
}
