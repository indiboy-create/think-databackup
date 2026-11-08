<?php

declare(strict_types=1);

namespace youvision\think\databackup;

use FilesystemIterator;

final class Storage
{
    private string $path;

    private int $part;

    private bool $compress;

    private int $level;

    private int $chmod;

    private mixed $write_handle = null;

    private mixed $read_handle = null;

    private array $file = [];

    private int $written = 0;

    private ?bool $reader_compressed = null;

    public function __construct(array $config)
    {
        $this->path = rtrim(str_replace('\\', '/', (string) ($config['path'] ?? '')), '/') . '/';
        $this->part = max(1024, (int) ($config['part'] ?? 20971520));
        $this->compress = (bool) ($config['compress'] ?? false);
        $this->level = min(9, max(0, (int) ($config['level'] ?? 9)));
        $this->chmod = (int) ($config['chmod'] ?? 0755);

        $this->ensure_path();
    }

    public function path(): string
    {
        return $this->path;
    }


    public function begin(string $name, int $part = 1): void
    {
        $part = max(1, $part);

        if ($this->write_handle !== null
            && ($this->file['name'] ?? null) === $name
            && (int) ($this->file['part'] ?? 0) === $part) {
            return;
        }

        $this->close_write();
        $this->file = ['name' => $name, 'part' => $part];
        $this->written = 0;
        $this->open_write();
    }

    public function write(string $sql): int
    {
        if ($this->write_handle === null) {
            throw new BackupException('备份文件尚未打开，请先设置备份文件名');
        }

        $length = strlen($sql);

        if ($this->written > 0 && $this->written + $length > $this->part) {
            $this->close_write();
            $this->file['part']++;
            $this->written = 0;
            $this->open_write();
        }

        $result = $this->compress
            ? @gzwrite($this->write_handle, $sql)
            : @fwrite($this->write_handle, $sql);

        if ($result === false || $result !== $length) {
            throw new BackupException('写入备份文件失败：' . $this->current_path());
        }

        $this->written += $length;

        return (int) $this->file['part'];
    }

    public function current_path(): string
    {
        return $this->path_for_write(
            (string) ($this->file['name'] ?? ''),
            (int) ($this->file['part'] ?? 1)
        );
    }

    public function current_size(): int
    {
        $path = $this->current_path();

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * 获取备份文件聚合列表，按备份时间倒序排列。
     */
    public function files(): array
    {
        $this->ensure_path();

        $list = [];
        $iterator = new FilesystemIterator($this->path, FilesystemIterator::KEY_AS_FILENAME);

        foreach ($iterator as $name => $item) {
            if (!$item->isFile()) {
                continue;
            }

            if (!preg_match('/^(\d{8}-\d{6})-(\d+)\.sql(\.gz)?$/i', $name, $matches)) {
                continue;
            }

            $file_name = $matches[1];
            $part = (int) $matches[2];
            $is_compressed = isset($matches[3]) && $matches[3] !== '';

            if (!isset($list[$file_name])) {
                $list[$file_name] = [
                    'name' => $file_name,
                    'time' => $this->name_to_time($file_name),
                    'datetime' => date('Y-m-d H:i:s', $this->name_to_time($file_name)),
                    'part' => 0,
                    'size' => 0,
                    'compress' => $is_compressed ? 'GZ' : '-',
                    'compressed' => $is_compressed,
                    'files' => [],
                    'complete' => false,
                ];
            }

            $list[$file_name]['part'] = max($list[$file_name]['part'], $part);
            $list[$file_name]['size'] += (int) $item->getSize();
            $list[$file_name]['compressed'] = $list[$file_name]['compressed'] || $is_compressed;
            $list[$file_name]['compress'] = $list[$file_name]['compressed'] ? 'GZ' : '-';
            $list[$file_name]['files'][$part] = $item->getPathname();
        }

        foreach ($list as &$item) {
            ksort($item['files']);
            $parts = array_keys($item['files']);
            $item['complete'] = $parts === range(1, (int) $item['part']);
        }
        unset($item);

        uasort($list, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return array_values($list);
    }

    public function find_by_time(int $time): ?array
    {
        foreach ($this->files() as $file) {
            if ((int) $file['time'] === $time) {
                return $file;
            }
        }

        return null;
    }

    public function has_part(string $name, int $part): bool
    {
        return is_file($this->path_for_read($name, $part, false));
    }


    public function delete_by_time(int $time): int
    {
        $file = $this->find_by_time($time);

        if ($file === null) {
            throw new BackupException('备份文件不存在：' . date('Y-m-d H:i:s', $time));
        }

        return $this->delete_name((string) $file['name']);
    }

    public function delete_name(string $name): int
    {
        $count = 0;
        foreach ($this->files() as $file) {
            if ($file['name'] !== $name) {
                continue;
            }

            foreach ($file['files'] as $path) {
                if (!is_file($path)) {
                    continue;
                }

                if (!@unlink($path)) {
                    throw new BackupException('删除备份文件失败：' . $path);
                }

                $count++;
            }
        }

        return $count;
    }

    public function clean(int $days): int
    {
        if ($days <= 0) {
            throw new BackupException('保留天数必须大于 0');
        }

        $expire = time() - $days * 86400;
        $count = 0;

        foreach ($this->files() as $file) {
            if ((int) $file['time'] >= $expire) {
                continue;
            }

            $count += $this->delete_name((string) $file['name']);
        }

        return $count;
    }

    public function open_reader(string $name, int $part): void
    {
        $this->close_reader();

        $path = $this->path_for_read($name, $part, true);
        $compressed = str_ends_with(strtolower($path), '.gz');
        $handle = $compressed ? @gzopen($path, 'rb') : @fopen($path, 'rb');

        if ($handle === false || $handle === null) {
            throw new BackupException('打开备份文件失败：' . $path);
        }

        $this->read_handle = $handle;
        $this->reader_compressed = $compressed;
    }

    public function read_line(): string|false
    {
        if ($this->read_handle === null) {
            throw new BackupException('备份文件尚未打开');
        }

        $line = $this->reader_compressed
            ? gzgets($this->read_handle)
            : fgets($this->read_handle);

        return $line === false ? false : $line;
    }

    public function eof(): bool
    {
        if ($this->read_handle === null) {
            return true;
        }

        return $this->reader_compressed
            ? gzeof($this->read_handle)
            : feof($this->read_handle);
    }

    public function seek(int $offset): void
    {
        if ($this->read_handle === null || $offset < 0) {
            return;
        }

        if ($offset === 0) {
            $this->reader_compressed ? gzrewind($this->read_handle) : rewind($this->read_handle);

            return;
        }

        $result = $this->reader_compressed
            ? gzseek($this->read_handle, $offset)
            : fseek($this->read_handle, $offset);

        if ($result === -1) {
            throw new BackupException('定位备份文件读取位置失败');
        }
    }

    public function tell(): int
    {
        if ($this->read_handle === null) {
            return 0;
        }

        $position = $this->reader_compressed
            ? gztell($this->read_handle)
            : ftell($this->read_handle);

        return $position === false ? 0 : (int) $position;
    }

    public function close_reader(): void
    {
        if ($this->read_handle !== null) {
            $this->reader_compressed ? @gzclose($this->read_handle) : @fclose($this->read_handle);
        }

        $this->read_handle = null;
        $this->reader_compressed = null;
    }

    public function close(): void
    {
        $this->close_write();
        $this->close_reader();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function close_write(): void
    {
        if ($this->write_handle !== null) {
            $this->compress ? @gzclose($this->write_handle) : @fclose($this->write_handle);
        }

        $this->write_handle = null;
    }

    private function open_write(): void
    {
        $path = $this->path_for_write(
            (string) $this->file['name'],
            (int) $this->file['part']
        );

        $this->write_handle = $this->compress
            ? @gzopen($path, 'a' . $this->level)
            : @fopen($path, 'ab');

        if ($this->write_handle === false || $this->write_handle === null) {
            throw new BackupException('创建备份文件失败：' . $path);
        }

        $this->written = is_file($path) ? (int) filesize($path) : 0;
    }

    private function path_for_write(string $name, int $part): string
    {
        $this->assert_name($name);
        $path = $this->path . $name . '-' . $part . '.sql';

        return $this->compress ? $path . '.gz' : $path;
    }

    private function path_for_read(string $name, int $part, bool $strict): string
    {
        $this->assert_name($name);
        $plain = $this->path . $name . '-' . $part . '.sql';
        $compressed = $plain . '.gz';

        if (is_file($plain)) {
            return $plain;
        }

        if (is_file($compressed)) {
            return $compressed;
        }

        if ($strict) {
            throw new BackupException('备份文件不存在：' . $plain);
        }

        return $plain;
    }

    private function assert_name(string $name): void
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $name)) {
            throw new BackupException('备份文件名格式不正确，应为 Ymd-His');
        }
    }

    private function name_to_time(string $name): int
    {
        $datetime = sprintf(
            '%s-%s-%s %s:%s:%s',
            substr($name, 0, 4),
            substr($name, 4, 2),
            substr($name, 6, 2),
            substr($name, 9, 2),
            substr($name, 11, 2),
            substr($name, 13, 2)
        );

        $time = strtotime($datetime);

        return $time === false ? 0 : $time;
    }

    private function ensure_path(): void
    {
        if (is_dir($this->path)) {
            return;
        }

        if (!@mkdir($this->path, $this->chmod, true) && !is_dir($this->path)) {
            throw new BackupException('备份目录创建失败：' . $this->path);
        }
    }
}