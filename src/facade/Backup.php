<?php

declare(strict_types=1);

namespace youvision\think\databackup\facade;

use think\Facade;
use youvision\think\databackup\Backup as BackupService;

/**
 * @method static array tables()
 * @method static array columns(string $table)
 * @method static array files()
 * @method static array backup(string $table, int $start = 0)
 * @method static array backup_to_end(array $tables = [], ?callable $progress = null)
 * @method static array restore(string $name, int $part = 1, int $offset = 0)
 * @method static array restore_group(int $time)
 * @method static array repair(string|array $tables)
 * @method static array optimize(string|array $tables)
 * @method static int delete(int $time)
 * @method static int clean(?int $days = null)
 * @method static array finish()
 * @method static \youvision\think\databackup\Backup set_file(?array $file = null)
 * @method static array get_file()
 */
class Backup extends Facade
{
    protected static function getFacadeClass(): string
    {
        return BackupService::class;
    }
}