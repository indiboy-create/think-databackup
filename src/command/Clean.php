<?php

declare(strict_types=1);

namespace youvision\think\databackup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use youvision\think\databackup\Backup as Databackup;

class Clean extends Command
{
    protected function configure(): void
    {
        $this->setName('databackup:clean')
            ->addOption('days', null, Option::VALUE_REQUIRED, '保留最近多少天的备份，默认 30 天', 30)
            ->setDescription('清理过期备份文件');
    }

    protected function execute(Input $input, Output $output): int
    {
        $days = (int) $input->getOption('days');

        if ($days <= 0) {
            $output->writeln('<error>保留天数必须大于 0。</error>');

            return 1;
        }

        try {
            $backup = new Databackup();
            $count = $backup->clean($days);

            $output->writeln(sprintf('<info>清理完成，共删除 %d 个备份文件（保留最近 %d 天）。</info>', $count, $days));

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}