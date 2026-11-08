<?php

declare(strict_types=1);

namespace youvision\think\databackup\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use youvision\think\databackup\Backup as Databackup;

class Files extends Command
{
    protected function configure(): void
    {
        $this->setName('databackup:list')
            ->setDescription('查看备份文件列表');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $backup = new Databackup();
            $files = $backup->files();

            if ($files === []) {
                $output->writeln('<comment>暂无备份文件。</comment>');

                return 0;
            }

            $output->writeln(sprintf('备份目录：%s', $backup->storage()->path()));
            $output->writeln('时间                  文件名              分卷  大小       压缩  完整');

            foreach ($files as $file) {
                $output->writeln(sprintf(
                    '%-19s  %-18s  %-4d  %-9s  %-4s  %s',
                    $file['datetime'],
                    $file['name'],
                    $file['part'],
                    $this->format_size((int) $file['size']),
                    $file['compress'],
                    $file['complete'] ? '是' : '否'
                ));
            }

            $output->writeln('');
            $output->writeln(sprintf('<info>共 %d 组备份文件。</info>', count($files)));

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }

    private function format_size(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . $units[$index];
    }
}