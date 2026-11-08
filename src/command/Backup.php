<?php

declare(strict_types=1);

namespace youvision\think\databackup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use youvision\think\databackup\Backup as Databackup;

class Backup extends Command
{
    protected function configure(): void
    {
        $this->setName('databackup:backup')
            ->addOption('tables', null, Option::VALUE_OPTIONAL, '指定要备份的表名，多个用英文逗号分隔')
            ->addOption('path', null, Option::VALUE_OPTIONAL, '备份文件保存目录')
            ->addOption('compress', null, Option::VALUE_OPTIONAL, '是否启用 gzip 压缩，1 启用 0 关闭')
            ->addOption('part', null, Option::VALUE_OPTIONAL, '单个备份卷的最大字节数')
            ->setDescription('备份数据库');
    }

    protected function execute(Input $input, Output $output): int
    {
        $config = [];

        $path = $input->getOption('path');
        if ($path !== null && $path !== '') {
            $config['path'] = (string) $path;
        }

        $compress = $input->getOption('compress');
        if ($compress !== null && $compress !== '') {
            $config['compress'] = (bool) (int) $compress;
        }

        $part = $input->getOption('part');
        if ($part !== null && $part !== '') {
            $config['part'] = (int) $part;
        }

        try {
            $backup = new Databackup($config);
            $tables = [];

            $option = (string) $input->getOption('tables');
            if ($option !== '') {
                $tables = array_values(array_filter(array_map('trim', explode(',', $option))));
                $map = [];

                foreach ($backup->tables() as $row) {
                    $map[(string) $row['name']] = (string) $row['name'];
                    $map[(string) $row['short_name']] = (string) $row['name'];
                }

                $tables = array_values(array_unique(array_map(
                    static fn (string $table): string => $map[$table] ?? $table,
                    $tables
                )));
                $missing = array_values(array_diff($tables, array_values($map)));

                if ($missing !== []) {
                    $output->writeln('<error>以下数据表不存在：' . implode(', ', $missing) . '</error>');

                    return 1;
                }
            }

            $output->writeln('<info>开始备份数据库...</info>');

            $result = $backup->backup_to_end($tables, static function (string $table, array $result) use ($output): void {
                $total = (int) $result['total'];
                $start = (int) $result['start'];
                $percent = $total > 0 ? (int) floor($start / $total * 100) : 100;

                $output->writeln(sprintf('  %s：%d/%d（%d%%）', $table, $start, $total, $percent));
            });

            $output->writeln(sprintf(
                '<info>备份完成：%s，共 %d 个分卷、%d 张表。</info>',
                $result['name'],
                $result['part'],
                $result['tables']
            ));

            if ($result['deleted'] > 0) {
                $output->writeln(sprintf('<comment>已清理 %d 个过期备份文件。</comment>', $result['deleted']));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}