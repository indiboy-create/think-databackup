# think-databackup

ThinkPHP 8 数据库备份与恢复扩展，支持分卷、gzip 压缩、分步备份/恢复、命令行以及过期备份清理。

## 特性

- 适配 ThinkPHP 8 / PHP 8.0+
- 自动读取 ThinkPHP 数据库连接配置，无需重复填写账号密码
- 按卷切分备份文件，适合大表分步备份
- 可选 gzip 压缩
- 支持 Web 分步备份与命令行一次性备份
- 支持按时间戳恢复、修复表、优化表、删除与清理备份
- 使用 PDO 转义导出数据，正确处理 NULL、换行、二进制与特殊字符
- 自动过滤视图，自动排除虚拟列与生成列
- 恢复前检查分卷连续性，避免使用不完整的备份

## 环境要求

- PHP >= 8.0，需启用 `pdo_mysql`、`zlib`
- ThinkPHP >= 8.0
- MySQL / MariaDB

## 安装

```bash
composer require youvision/think-databackup
```

## 配置

扩展自带默认配置。如需覆盖，在项目 `config` 目录创建 `databackup.php`，只写需要修改的项即可，其余项会自动使用默认值。

```php
<?php

return [
    'path' => runtime_path() . 'databackup' . DIRECTORY_SEPARATOR,
    'part' => 20 * 1024 * 1024,
    'compress' => false,
    'level' => 9,
    'connection' => '',
    'table_prefix' => null,
    'exclude' => ['log', '*_log'],
    'drop_table' => true,
    'insert_rows' => 100,
    'backup_rows' => 1000,
    'restore_rows' => 1000,
    'max_packet' => 1024 * 1024,
    'chmod' => 0755,
    'keep_days' => 0,
];
```

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `path` | `runtime_path() . 'databackup/'` | 备份文件保存目录。建议放在 Web 根目录之外，并确保 PHP 进程有读写权限。 |
| `part` | `20971520` | 单个备份卷的最大字节数，默认 20MB。达到上限后自动生成下一卷。 |
| `compress` | `false` | 是否启用 gzip 压缩。开启后文件名为 `*.sql.gz`。 |
| `level` | `9` | gzip 压缩级别，范围 0-9，0 不压缩，9 压缩率最高。 |
| `connection` | `''` | 数据库连接名。留空使用 `database.default` 指定的默认连接。 |
| `table_prefix` | `null` | 备份表前缀。`null` 自动读取连接的 `prefix`；空字符串表示备份全部业务表；也可显式指定前缀。 |
| `exclude` | `[]` | 排除的表，支持 `*`、`?` 通配符，匹配完整表名或去掉前缀后的表名。 |
| `drop_table` | `true` | 备份表结构时是否写入 `DROP TABLE IF EXISTS`。 |
| `insert_rows` | `100` | 每条 INSERT 语句包含的最大记录数。 |
| `backup_rows` | `1000` | 每次备份请求最多读取的记录数，用于控制 Web 分步备份的单次耗时。 |
| `restore_rows` | `1000` | 每次恢复请求最多执行的 SQL 条数，用于控制 Web 分步恢复的单次耗时。 |
| `max_packet` | `1048576` | 单条 SQL 语句的最大字节数，超过后自动拆分，避免超过 `max_allowed_packet`。 |
| `chmod` | `0755` | 创建备份目录时使用的权限。 |
| `keep_days` | `0` | 保留天数，0 表示不自动清理。调用 `finish()` 或执行命令行备份时生效。 |

## 服务注册

项目安装依赖后，扩展会通过 `composer.json` 的 `extra.think.services` 自动注册服务。

如果项目未启用自动发现，在 `app/service.php` 中手动添加：

```php
return [
    \youvision\think\databackup\service\Databackup::class,
];
```

注册后可获得：

- `app(\youvision\think\databackup\Backup::class)`
- 依赖注入 `Backup $backup`
- 门面 `\youvision\think\databackup\facade\Backup`
- 命令 `databackup:backup`、`databackup:list`、`databackup:restore`、`databackup:clean`

## 命令行

```bash
# 备份全部表
php think databackup:backup

# 只备份指定表，支持完整表名或去掉前缀后的表名
php think databackup:backup --tables=user,article

# 启用压缩并指定单卷大小（单位：字节）
php think databackup:backup --compress=1 --part=10485760

# 查看备份文件
php think databackup:list

# 按时间戳恢复（时间戳从 databackup:list 获取）
php think databackup:restore 1762531200

# 清理 30 天前的备份
php think databackup:clean --days=30
```

## Web 分步备份

控制器中每次请求只备份一页数据，前端根据返回结果继续请求，避免大库备份超时。

```php
use youvision\think\databackup\Backup;

class BackupController
{
    public function backup(Backup $backup)
    {
        $tables = array_column($backup->tables(), 'name');
        $table = (string) $this->request->param('table', $tables[0] ?? '');
        $start = (int) $this->request->param('start', 0);
        $state = (array) $this->request->param('file', []);

        if ($state !== []) {
            $backup->set_file([
                'name' => (string) $state['name'],
                'part' => (int) $state['part'],
            ]);
        }

        $result = $backup->backup($table, $start);

        return json([
            'table' => $table,
            'start' => $result['start'],
            'total' => $result['total'],
            'part' => $result['part'],
            'finished' => $result['finished'],
            'file' => $result['file'],
        ]);
    }
}
```

前端把 `result.file` 原样回传，即可在下一请求中继续写入同一个备份文件。`finished` 为 `true` 时切换到下一张表，并将 `start` 重置为 0。所有表备份完成后调用 `$backup->finish()` 关闭文件并执行 `keep_days` 清理。

## Web 分步恢复

```php
use youvision\think\databackup\Backup;

class RestoreController
{
    public function restore(Backup $backup)
    {
        $file = $backup->files()[0];
        $part = (int) $this->request->param('part', 1);
        $offset = (int) $this->request->param('offset', 0);

        $result = $backup->restore($file['name'], $part, $offset);

        return json($result);
    }
}
```

返回的 `part` 与 `offset` 原样回传，直到 `finished` 为 `true`。

## 常用 API

```php
use youvision\think\databackup\Backup;

$backup = app(Backup::class);

// 数据表
$tables = $backup->tables();
$columns = $backup->columns('tp_user');

// 备份文件
$files = $backup->files();
$backup->delete($time);
$backup->clean(30);

// 备份
$backup->set_file(); // 生成新的备份文件名，返回 $backup
$result = $backup->backup('tp_user', 0);

// 一次性备份全部表
$result = $backup->backup_to_end();

// 恢复
$result = $backup->restore_group($time);
$result = $backup->restore($name, $part, $offset);

// 维护
$backup->repair(['tp_user']);
$backup->optimize(['tp_user']);
```

门面方式：

```php
use youvision\think\databackup\facade\Backup;

$tables = Backup::tables();
$files = Backup::files();
```

## 备份文件命名

```
Ymd-His-卷号.sql
Ymd-His-卷号.sql.gz
```

例如 `20261108-230000-1.sql`、`20261108-230000-2.sql.gz`。同一时间戳的所有卷属于同一组备份。

## 注意事项

- 恢复会执行备份文件中的 `DROP TABLE IF EXISTS`，请先确认当前数据不再需要。
- 备份目录不要放在 Web 可访问目录；如必须放置，请在控制器中做好鉴权。
- 自动备份建议使用命令行配合系统定时任务，避免 Web 请求超时。
- 扩展仅支持 MySQL / MariaDB，其他数据库驱动会直接抛出异常。
- 备份不包含视图、存储过程、触发器，只备份基础表和表数据。
- 恢复前会校验分卷编号是否连续，分卷缺失时拒绝恢复。


## 许可证

MIT