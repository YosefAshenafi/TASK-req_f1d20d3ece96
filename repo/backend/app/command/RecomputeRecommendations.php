<?php
declare(strict_types=1);
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\service\RecommendationEngine;
use think\facade\Log;

class RecomputeRecommendations extends Command
{
    protected function configure(): void
    {
        $this->setName('recommendation:recompute')
             ->setDescription('Recompute tag popularity scores for the recommendation engine');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            (new RecommendationEngine())->recomputeTagPopularity();
            $output->writeln('Tag popularity scores recomputed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            Log::error('recompute_command_failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
