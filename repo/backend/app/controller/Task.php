<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use app\model\ActivityTask;
use app\model\Activity;
use app\exception\ForbiddenException;
use app\exception\NotFoundException;

class Task
{
    public function index(Request $request, int $id)
    {
        $activity = Activity::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        $tasks = ActivityTask::where('activity_id', $id)->select();
        return json(['code' => 200, 'msg' => 'ok', 'data' => $tasks->toArray()]);
    }

    public function store(Request $request, int $id)
    {
        if ($request->user_role !== 'team_lead' && $request->user_role !== 'admin') {
            throw new ForbiddenException('Team Lead access required');
        }

        $activity = Activity::find($id);
        if (!$activity) throw new NotFoundException('Activity not found');

        $data = $request->post();
        if (empty($data['title'])) {
            return json(['code' => 422, 'msg' => 'title is required', 'errors' => ['title' => 'required']], 422);
        }

        $task = ActivityTask::create([
            'activity_id'    => $id,
            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,
            'staffing_count' => (int)($data['staffing_count'] ?? 0),
            'checklist'      => $data['checklist'] ?? [],
            'assigned_to'    => $data['assigned_to'] ?? null,
            'created_by'     => $request->user_id,
        ]);

        return json(['code' => 201, 'msg' => 'Task created', 'data' => $task->toArray()], 201);
    }

    public function update(Request $request, int $id, int $tid)
    {
        if ($request->user_role !== 'team_lead' && $request->user_role !== 'admin') {
            throw new ForbiddenException('Team Lead access required');
        }

        $task = ActivityTask::find($tid);
        if (!$task || $task->activity_id !== $id) throw new NotFoundException('Task not found');

        // Team leads can only update tasks they created (admins can update any)
        if ($request->user_role === 'team_lead' && $task->created_by !== $request->user_id) {
            throw new ForbiddenException('You may only update your own tasks');
        }

        $data = $request->put();
        unset($data['activity_id'], $data['created_by']);
        ActivityTask::where('id', $tid)->update($data);

        return json(['code' => 200, 'msg' => 'Task updated', 'data' => ActivityTask::find($tid)->toArray()]);
    }

    public function destroy(Request $request, int $id, int $tid)
    {
        if ($request->user_role !== 'team_lead' && $request->user_role !== 'admin') {
            throw new ForbiddenException('Team Lead access required');
        }

        $task = ActivityTask::find($tid);
        if (!$task || $task->activity_id !== $id) throw new NotFoundException('Task not found');

        if ($request->user_role === 'team_lead' && $task->created_by !== $request->user_id) {
            throw new ForbiddenException('You may only delete your own tasks');
        }

        $task->delete();
        return json(['code' => 200, 'msg' => 'Task deleted', 'data' => []]);
    }
}
