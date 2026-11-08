<?php

declare(strict_types=1);

namespace youvision\think\databackup;

use PDO;
use think\db\ConnectionInterface;
use think\facade\Db;

final class Database
{
    private ?ConnectionInterface $connection = null;

    public function __construct(private array $config = [])
    {
    }

    public function connection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $name = (string) ($this->config['connection'] ?? '');
            try {
                $this->connection = $name === '' ? Db::connect() : Db::connect($name);
            } catch (\Throwable $e) {
                throw new BackupException('数据库连接失败：' . $e->getMessage(), 0, $e);
            }
        }

        return $this->connection;
    }

    public function driver(): string
    {
        $type = strtolower((string) $this->connection()->getConfig('type'));

        if (str_contains($type, 'mysql') || str_contains($type, 'maria')) {
            return 'mysql';
        }

        return $type === '' ? 'unknown' : $type;
    }

    /**
     * 获取当前数据库中的业务表列表，视图与空名称会被自动过滤。
     */
    public function table_list(): array
    {
        $this->assert_supported();
        $prefix = $this->prefix();
        $exclude = (array) ($this->config['exclude'] ?? []);

        try {
            $rows = $this->connection()->query('SHOW TABLE STATUS');
        } catch (\Throwable $e) {
            throw new BackupException('读取数据表列表失败：' . $e->getMessage(), 0, $e);
        }

        $list = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $name = (string) ($row['name'] ?? '');

            if ($name === '' || !isset($row['engine']) || $row['engine'] === null) {
                continue;
            }

            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }

            if ($this->is_excluded($name, $prefix, $exclude)) {
                continue;
            }

            $row['short_name'] = $prefix !== '' && str_starts_with($name, $prefix)
                ? substr($name, strlen($prefix))
                : $name;
            $list[] = $row;
        }

        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $list;
    }

    public function columns(string $table): array
    {
        $this->assert_supported();

        try {
            $rows = $this->connection()->query('SHOW COLUMNS FROM ' . $this->quote_identifier($table));
        } catch (\Throwable $e) {
            throw new BackupException("读取数据表 {$table} 字段失败：" . $e->getMessage(), 0, $e);
        }

        return array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    public function create_table(string $table): string
    {
        $this->assert_supported();

        try {
            $rows = $this->connection()->query('SHOW CREATE TABLE ' . $this->quote_identifier($table));
        } catch (\Throwable $e) {
            throw new BackupException("读取数据表 {$table} 结构失败：" . $e->getMessage(), 0, $e);
        }

        $row = $rows[0] ?? [];
        foreach ($row as $key => $value) {
            if (stripos((string) $key, 'create ') === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }

        $values = array_values($row);
        if (isset($values[1]) && is_string($values[1]) && $values[1] !== '') {
            return $values[1];
        }

        throw new BackupException("无法获取数据表 {$table} 的建表语句");
    }

    public function count(string $table): int
    {
        try {
            $rows = $this->connection()->query(
                'SELECT COUNT(*) AS count FROM ' . $this->quote_identifier($table)
            );
        } catch (\Throwable $e) {
            throw new BackupException("统计数据表 {$table} 记录数失败：" . $e->getMessage(), 0, $e);
        }

        return (int) (array_values($rows[0] ?? [0])[0] ?? 0);
    }

    public function rows(string $table, int $offset, int $limit, array $columns = []): array
    {
        $offset = max(0, $offset);
        $limit = max(1, $limit);
        $select = '*';

        if ($columns !== []) {
            $select = implode(', ', array_map(
                fn (string $column): string => $this->quote_identifier($column),
                $columns
            ));
        }

        try {
            return $this->connection()->query(sprintf(
                'SELECT %s FROM %s LIMIT %d, %d',
                $select,
                $this->quote_identifier($table),
                $offset,
                $limit
            ));
        } catch (\Throwable $e) {
            throw new BackupException("读取数据表 {$table} 数据失败：" . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取可写入的字段列表，自动排除虚拟列与生成列。
     *
     * @return array<int,string>
     */
    public function insertable_columns(string $table): array
    {
        $columns = [];

        foreach ($this->columns($table) as $column) {
            $extra = strtolower((string) ($column['extra'] ?? ''));

            if (str_contains($extra, 'generated')) {
                continue;
            }

            $field = (string) ($column['field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }

        return $columns;
    }

    public function execute(string $sql): int
    {
        try {
            return (int) $this->connection()->execute($sql);
        } catch (\Throwable $e) {
            $preview = substr(trim($sql), 0, 200);
            throw new BackupException('SQL 执行失败：' . $e->getMessage() . '，语句：' . $preview, 0, $e);
        }
    }

    public function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;
        $pdo = $this->pdo();

        if ($pdo instanceof PDO) {
            $quoted = $pdo->quote($value);
            if ($quoted !== false) {
                return $quoted;
            }
        }

        return "'" . str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value
        ) . "'";
    }

    public function repair(string|array $tables): array
    {
        return $this->maintain('REPAIR TABLE', $tables);
    }

    public function optimize(string|array $tables): array
    {
        return $this->maintain('OPTIMIZE TABLE', $tables);
    }

    public function server_info(): array
    {
        $connection = $this->connection();

        return [
            'host' => (string) $connection->getConfig('hostname'),
            'port' => (string) $connection->getConfig('hostport'),
            'database' => (string) $connection->getConfig('database'),
            'charset' => (string) ($connection->getConfig('charset') ?: 'utf8mb4'),
            'prefix' => (string) $connection->getConfig('prefix'),
        ];
    }

    private function maintain(string $operation, string|array $tables): array
    {
        $tables = is_array($tables) ? $tables : [$tables];
        $tables = array_values(array_unique(array_filter(
            array_map(static fn (mixed $table): string => trim((string) $table), $tables),
            static fn (string $table): bool => $table !== ''
        )));

        if ($tables === []) {
            throw new BackupException('请指定要操作的数据表');
        }

        $result = [];
        foreach ($tables as $table) {
            try {
                $result[$table] = $this->connection()->query(
                    $operation . ' ' . $this->quote_identifier($table)
                );
            } catch (\Throwable $e) {
                throw new BackupException(
                    "{$operation} 数据表 {$table} 失败：" . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $result;
    }

    private function assert_supported(): void
    {
        $driver = $this->driver();
        if ($driver !== 'mysql') {
            throw new BackupException('当前扩展仅支持 MySQL / MariaDB，当前连接驱动：' . ($driver ?: 'unknown'));
        }
    }

    private function prefix(): string
    {
        if (array_key_exists('table_prefix', $this->config) && $this->config['table_prefix'] !== null) {
            return (string) $this->config['table_prefix'];
        }

        return (string) $this->connection()->getConfig('prefix');
    }

    private function is_excluded(string $table, string $prefix, array $patterns): bool
    {
        $short_name = $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if ($this->wildcard_match($pattern, $table) || $this->wildcard_match($pattern, $short_name)) {
                return true;
            }
        }

        return false;
    }

    private function wildcard_match(string $pattern, string $value): bool
    {
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $value);
    }

    private function pdo(): ?PDO
    {
        $connection = $this->connection();

        if (!method_exists($connection, 'getPdo')) {
            return null;
        }

        try {
            $pdo = $connection->getPdo();

            return $pdo instanceof PDO ? $pdo : null;
        } catch (\Throwable) {
            return null;
        }
    }
}