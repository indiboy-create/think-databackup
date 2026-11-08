<?php

declare(strict_types=1);

namespace youvision\think\databackup;

final class Backup
{
    private array $config;

    private Database $database;

    private Storage $storage;

    private array $file = [];

    private array $totals = [];

    private array $insert_columns = [];

    public function __construct(array $config = [])
    {
        $this->config = $this->normalize_config($config);
        $this->database = new Database($this->config);
        $this->storage = new Storage($this->config);
        $this->set_file();
    }

    public static function make(array $config = []): self
    {
        return new self($config);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function storage(): Storage
    {
        return $this->storage;
    }

    /**
     * 设置本次备份或恢复使用的文件名与卷号。
     *
     * @param array{name:string,part:int}|null $file
     */
    public function set_file(?array $file = null): self
    {
        $this->totals = [];
        $this->insert_columns = [];

        if ($file === null) {
            $this->file = ['name' => date('Ymd-His'), 'part' => 1];

            return $this;
        }

        $name = (string) ($file['name'] ?? '');
        $part = max(1, (int) ($file['part'] ?? 1));

        if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
            throw new BackupException('备份文件名格式不正确，应为 Ymd-His');
        }

        $this->file = ['name' => $name, 'part' => $part];

        return $this;
    }

    public function get_file(): array
    {
        return $this->file;
    }

    /**
     * 当前数据库的业务表列表。
     */
    public function tables(): array
    {
        return $this->database->table_list();
    }

    public function columns(string $table): array
    {
        return $this->database->columns($table);
    }

    /**
     * 备份文件列表，按时间倒序。
     */
    public function files(): array
    {
        return $this->storage->files();
    }

    /**
     * 备份单张表的一页数据。
     *
     * 返回 start 表示下一页的起始行，finished 表示该表是否已备份完成。
     *
     * @return array{start:int,total:int,part:int,finished:bool,file:array}
     */
    public function backup(string $table, int $start = 0): array
    {
        if ($table === '') {
            throw new BackupException('请指定要备份的数据表');
        }

        $start = max(0, $start);
        $this->storage->begin((string) $this->file['name'], (int) $this->file['part']);

        if ($start === 0) {
            if ($this->storage->current_size() === 0) {
                $this->write_header();
            }

            $this->write_structure($table);
        }

        $total = $this->totals[$table] ??= $this->database->count($table);
        $columns = $this->insert_columns[$table] ??= $this->database->insertable_columns($table);
        $page_size = max(1, (int) ($this->config['backup_rows'] ?? 1000));
        $rows = $this->database->rows($table, $start, $page_size, $columns);
        $next = $start;

        if ($rows !== []) {
            if ($start === 0) {
                $this->storage->write("--\n-- Records of `{$table}`\n--\n");
            }

            $this->write_rows($table, $rows, $columns);
            $next = $start + count($rows);
        }


        return [
            'start' => $next,
            'total' => $total,
            'part' => (int) $this->file['part'],
            'finished' => $rows === [] || $next >= $total,
            'file' => $this->file,
        ];
    }

    /**
     * 一次性备份全部指定表，命令行入口使用。
     *
     * @param array<int,string> $tables
     * @param callable|null     $progress function (string $table, array $result): void
     * @return array{name:string,part:int,tables:int,deleted:int}
     */
    public function backup_to_end(array $tables = [], ?callable $progress = null): array
    {
        if ($tables === []) {
            $tables = array_map(static fn (array $row): string => (string) $row['name'], $this->tables());
        }

        if ($tables === []) {
            throw new BackupException('当前数据库没有可备份的数据表');
        }

        foreach ($tables as $table) {
            $start = 0;

            do {
                $result = $this->backup($table, $start);
                $start = $result['start'];

                if ($progress !== null) {
                    $progress($table, $result);
                }
            } while (!$result['finished']);
        }

        $finish = $this->finish();

        return [
            'name' => (string) $this->file['name'],
            'part' => (int) $this->file['part'],
            'tables' => count($tables),
            'deleted' => $finish['deleted'],
        ];
    }

    /**
     * 刷新文件句柄并按 keep_days 清理过期备份。
     *
     * @return array{file:array,deleted:int}
     */
    public function finish(): array
    {
        $this->storage->close();
        $deleted = 0;
        $keep_days = (int) ($this->config['keep_days'] ?? 0);

        if ($keep_days > 0) {
            $deleted = $this->storage->clean($keep_days);
        }

        return ['file' => $this->file, 'deleted' => $deleted];
    }

    /**
     * 恢复指定备份卷的一页 SQL。
     *
     * 返回 offset 表示下一读取位置，finished 表示全部备份卷是否恢复完成。
     *
     * @return array{offset:int,part:int,executed:int,finished:bool}
     */
    public function restore(string $name, int $part = 1, int $offset = 0): array
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
            throw new BackupException('备份文件名格式不正确，应为 Ymd-His');
        }

        $part = max(1, $part);
        $offset = max(0, $offset);
        $this->storage->open_reader($name, $part);
        $this->storage->seek($offset);

        try {
            $this->database->execute('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            $buffer = '';
            $max_statements = max(1, (int) ($this->config['restore_rows'] ?? 1000));

            while (!$this->storage->eof() && $executed < $max_statements) {
                $line = $this->storage->read_line();

                if ($line === false) {
                    break;
                }

                if ($buffer === '' && $this->is_ignorable_line($line)) {
                    continue;
                }

                $buffer .= $line;

                if ($this->is_statement_end($buffer)) {
                    $this->execute_buffer($buffer);
                    $buffer = '';
                    $executed++;
                }
            }

            if ($buffer !== '' && !$this->is_ignorable_line($buffer)) {
                $this->execute_buffer($buffer);
                $executed++;
            }

            $at_end = $this->storage->eof();
            $has_next = $at_end && $this->storage->has_part($name, $part + 1);
            $finished = $at_end && !$has_next;
            $next_offset = ($finished || $has_next) ? 0 : $this->storage->tell();
            $next_part = $has_next ? $part + 1 : $part;

            if ($finished) {
                $this->database->execute('SET FOREIGN_KEY_CHECKS = 1');
            }

            return [
                'offset' => $next_offset,
                'part' => $next_part,
                'executed' => $executed,
                'finished' => $finished,
            ];
        } catch (\Throwable $e) {
            try {
                $this->database->execute('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
            }

            if ($e instanceof BackupException) {
                throw $e;
            }

            throw new BackupException('恢复备份失败：' . $e->getMessage(), 0, $e);
        } finally {
            $this->storage->close_reader();
        }
    }

    /**
     * 按时间戳恢复整组备份文件。
     *
     * @return array{name:string,part:int,executed:int}
     */
    public function restore_group(int $time): array
    {
        $file = $this->storage->find_by_time($time);

        if ($file === null) {
            throw new BackupException('备份文件不存在：' . date('Y-m-d H:i:s', $time));
        }

        if (empty($file['complete'])) {
            throw new BackupException('备份文件分卷不完整，无法恢复：' . $file['name']);
        }

        $part = 1;
        $offset = 0;
        $executed = 0;

        while (true) {
            $result = $this->restore((string) $file['name'], $part, $offset);
            $executed += $result['executed'];

            if ($result['finished']) {
                $part = $result['part'];
                break;
            }

            $part = $result['part'];
            $offset = $result['offset'];
        }

        return ['name' => (string) $file['name'], 'part' => $part, 'executed' => $executed];
    }

    public function delete(int $time): int
    {
        return $this->storage->delete_by_time($time);
    }

    public function clean(?int $days = null): int
    {
        $days ??= (int) ($this->config['keep_days'] ?? 0);

        if ($days <= 0) {
            throw new BackupException('请指定大于 0 的保留天数');
        }

        return $this->storage->clean($days);
    }

    public function repair(string|array $tables): array
    {
        return $this->database->repair($tables);
    }

    public function optimize(string|array $tables): array
    {
        return $this->database->optimize($tables);
    }

    private function write_header(): void
    {
        $info = $this->database->server_info();
        $part = (int) $this->file['part'];
        $charset = $info['charset'] !== '' ? $info['charset'] : 'utf8mb4';
        $host = $info['host'];

        if ($info['port'] !== '') {
            $host .= ':' . $info['port'];
        }

        $sql = "-- ----------------------------------------\n";
        $sql .= "-- ThinkPHP 8 Database Backup\n";
        $sql .= "-- Host     : {$host}\n";
        $sql .= "-- Database : {$info['database']}\n";
        $sql .= "-- Date     : " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Part     : {$part}\n";
        $sql .= "-- ----------------------------------------\n\n";
        $sql .= "SET NAMES {$charset};\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        $this->file['part'] = $this->storage->write($sql);
    }

    private function write_structure(string $table): void
    {
        $identifier = $this->database->quote_identifier($table);
        $sql = "--\n-- Table structure for {$identifier}\n--\n";

        if ((bool) ($this->config['drop_table'] ?? true)) {
            $sql .= "DROP TABLE IF EXISTS {$identifier};\n";
        }

        $sql .= $this->database->create_table($table) . ";\n\n";
        $this->file['part'] = $this->storage->write($sql);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $columns
     */
    private function write_rows(string $table, array $rows, array $columns): void
    {
        $identifier = $this->database->quote_identifier($table);
        $column_sql = '';

        if ($columns !== []) {
            $column_sql = ' (' . implode(', ', array_map(
                fn (string $column): string => $this->database->quote_identifier($column),
                $columns
            )) . ')';
        }

        $max_rows = max(1, (int) ($this->config['insert_rows'] ?? 100));
        $max_packet = max(1024, (int) ($this->config['max_packet'] ?? 1048576));
        $batch = [];
        $batch_size = 0;

        foreach ($rows as $row) {
            $values = array_map(fn (mixed $value): string => $this->database->quote($value), array_values($row));
            $tuple = '(' . implode(', ', $values) . ')';
            $tuple_size = strlen($tuple) + 2;

            if ($batch !== [] && ($batch_size + $tuple_size > $max_packet || count($batch) >= $max_rows)) {
                $this->flush_rows($identifier, $column_sql, $batch);
                $batch = [];
                $batch_size = 0;
            }

            $batch[] = $tuple;
            $batch_size += $tuple_size;
        }

        if ($batch !== []) {
            $this->flush_rows($identifier, $column_sql, $batch);
        }
    }

    /**
     * @param array<int,string> $batch
     */
    private function flush_rows(string $identifier, string $column_sql, array $batch): void
    {
        $sql = "INSERT INTO {$identifier}{$column_sql} VALUES " . implode(",\n", $batch) . ";\n";
        $this->file['part'] = $this->storage->write($sql);
    }

    private function execute_buffer(string $buffer): void
    {
        $sql = trim($buffer);

        if ($sql === '') {
            return;
        }

        $sql = rtrim($sql);
        if (!str_ends_with($sql, ';')) {
            $sql .= ';';
        }

        $this->database->execute($sql);
    }

    private function is_statement_end(string $buffer): bool
    {
        return str_ends_with(rtrim($buffer), ';');
    }

    private function is_ignorable_line(string $line): bool
    {
        $trimmed = ltrim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '--')
            || str_starts_with($trimmed, '#');
    }

    private function normalize_config(array $config): array
    {
        $defaults = include __DIR__ . '/config/databackup.php';
        $global = function_exists('config') ? (array) config('databackup', []) : [];
        $config = array_replace_recursive($defaults, $global, $config);

        $config['part'] = max(1024, (int) $config['part']);
        $config['compress'] = (bool) $config['compress'];
        $config['level'] = min(9, max(0, (int) $config['level']));
        $config['insert_rows'] = max(1, (int) $config['insert_rows']);
        $config['backup_rows'] = max(1, (int) $config['backup_rows']);
        $config['restore_rows'] = max(1, (int) $config['restore_rows']);
        $config['max_packet'] = max(1024, (int) $config['max_packet']);
        $config['chmod'] = (int) $config['chmod'];
        $config['keep_days'] = max(0, (int) $config['keep_days']);
        $path = trim((string) $config['path']);

        if ($path === '') {
            throw new BackupException('备份目录不能为空');
        }

        $config['path'] = rtrim(str_replace('\\', '/', $path), '/') . '/';

        return $config;
    }
}