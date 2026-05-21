<?php
declare(strict_types=1);
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\service\OrderService;
use think\facade\Log;

class AutoCancelOrders extends Command
{
    protected function configure(): void
    {
        $this->setName('order:auto-cancel')
             ->setDescription('Cancel orders stuck in Pending Payment for more than 30 minutes');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $count = (new OrderService())->autoCancelExpiredOrders();
            $output->writeln("Auto-canceled {$count} expired orders.");
            Log::info('auto_cancel_command', ['count' => $count]);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            Log::error('auto_cancel_command_failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
