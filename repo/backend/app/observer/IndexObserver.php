<?php
declare(strict_types=1);
namespace app\observer;

use think\Model;
use app\service\SearchIndexService;
use think\facade\Log;

class IndexObserver
{
    private static array $entityMap = [
        'app\\model\\Activity' => ['type' => 'activity', 'method' => 'indexActivity'],
    ];

    public function afterSave(Model $model): void
    {
        $class = get_class($model);
        if (!isset(self::$entityMap[$class])) return;

        try {
            $config  = self::$entityMap[$class];
            $service = new SearchIndexService();
            $service->{$config['method']}((int)$model->id);
        } catch (\Throwable $e) {
            Log::warning('observer_index_failed', ['class' => $class, 'id' => $model->id, 'error' => $e->getMessage()]);
        }
    }

    public function afterDelete(Model $model): void
    {
        $class = get_class($model);
        if (!isset(self::$entityMap[$class])) return;

        try {
            $config = self::$entityMap[$class];
            (new SearchIndexService())->deleteIndex($config['type'], (int)$model->id);
        } catch (\Throwable $e) {
            Log::warning('observer_delete_index_failed', ['class' => $class, 'id' => $model->id, 'error' => $e->getMessage()]);
        }
    }
}
