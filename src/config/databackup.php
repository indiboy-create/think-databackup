<?php

declare(strict_types=1);

$default_path = function_exists('runtime_path')
    ? runtime_path() . 'databackup' . DIRECTORY_SEPARATOR
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'databackup' . DIRECTORY_SEPARATOR;

return [
    // 备份文件存放目录，建议放在项目 runtime 目录之外，并确保 PHP 进程拥有读写权限。
    'path' => $default_path,

    // 单个备份卷的最大字节数，达到上限后自动生成下一卷（默认 20MB）。
    'part' => 20 * 1024 * 1024,

    // 是否使用 gzip 压缩备份文件。开启后文件名以 .sql.gz 结尾。
    'compress' => false,

    // gzip 压缩级别，范围 0-9，0 为不压缩，9 为最高压缩率。
    'level' => 9,

    // 数据库连接名，留空表示使用 database.default 指定的默认连接。
    'connection' => '',

    // 需要备份的表前缀。留空字符串表示备份当前数据库的全部业务表；
    // 设置为 null 时自动读取当前数据库连接配置中的 prefix。
    'table_prefix' => null,

    // 排除的表名，支持通配符 * 和 ?，匹配完整表名或去掉前缀后的表名。
    // 例如 ['log', '*_log', 'tp_migrations']。
    'exclude' => [],

    // 备份表结构时是否写入 DROP TABLE IF EXISTS 语句。
    'drop_table' => true,

    // 每条 INSERT 语句包含的最大记录数，数值越大语句越长、恢复越快。
    'insert_rows' => 100,

    // 每次备份请求最多读取的记录数，用于 Web 分步备份，避免单次请求超时。
    'backup_rows' => 1000,

    // 每次恢复请求最多执行的 SQL 条数，用于 Web 分步恢复，避免单次请求超时。
    'restore_rows' => 1000,

    // 单条 SQL 语句允许的最大字节数，超过后自动拆分，避免超过 max_allowed_packet。
    'max_packet' => 1024 * 1024,

    // 创建备份目录时使用的权限值。
    'chmod' => 0755,

    // 自动清理的保留天数，0 表示不自动清理。手动清理请使用 databackup:clean 命令。
    'keep_days' => 0,
];