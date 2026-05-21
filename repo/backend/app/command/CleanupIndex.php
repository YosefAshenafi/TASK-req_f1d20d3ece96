<?php
declare(strict_types=1);
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class CleanupIndex extends Command
{
    protected function configure(): void
    {
        $this->setName('index:cleanup')->setDescription('Remove orphaned search index entries older than 7 days');
    }

    protected function execute(Input $input, Output $output): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $count  = 0;

        try {
            // Phase 1: process the orphan candidates queue
            $orphans = Db::table('index_orphan_candidates')
                ->where('deleted_at', '<=', $cutoff)
                ->select();

            foreach ($orphans as $orphan) {
                Db::table('search_index')
                    ->where('entity_type', $orphan['entity_type'])
                    ->where('entity_id',   $orphan['entity_id'])
                    ->delete();
                Db::table('logistics_index')
                    ->where('entity_type', $orphan['entity_type'])
                    ->where('entity_id',   $orphan['entity_id'])
                    ->delete();
                $count++;
            }

            Db::table('index_orphan_candidates')->where('deleted_at', '<=', $cutoff)->delete();

            // Phase 2: catch-all orphan check for activities
            $deletedCount = Db::execute(
                "DELETE si FROM search_index si
                 LEFT JOIN activities a ON si.entity_type = 'activity' AND si.entity_id = a.id
                 WHERE si.entity_type = 'activity' AND a.id IS NULL"
            );
            $count += $deletedCount;

            $output->writeln("Cleaned up {$count} orphaned index entries.");
            Log::info('index_cleanup', ['removed' => $count, 'cutoff' => $cutoff]);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            Log::error('index_cleanup_failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
