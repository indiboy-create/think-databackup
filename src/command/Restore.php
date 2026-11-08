<?php

declare(strict_types=1);

namespace youvision\think\databackup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use youvision\think\databackup\Backup as Databackup;

class Restore extends Command
{
    protected function configure(): void
    {
        $this->setName('databackup:restore')
            ->addArgument('time', Argument::REQUIRED, '备份时间戳，可从 databackup:list 查看')
            ->setDescription('恢复数据库备份');
    }

    protected function execute(Input $input, Output $output): int
    {
        $time = (int) $input->getArgument('time');

        if ($time <= 0) {
            $output->writeln('<error>请传入正确的备份时间戳。</error>');

            return 1;
        }

        try {
            $backup = new Databackup();
            $output->writeln('<comment>恢复操作会覆盖同名数据表，请确认已备份当前数据。</comment>');

            $result = $backup->restore_group($time);

            $output->writeln(sprintf(
                '<info>恢复完成：%s，共恢复至第 %d 个分卷，执行 %d 条 SQL。</info>',
                $result['name'],
                $result['part'],
                $result['executed']
            ));

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }
    }
}