<?php
declare(strict_types=1);

namespace app\controller;

use app\exception\ForbiddenException;
use app\exception\NotFoundException;
use app\model\DashboardExport;
use app\model\DashboardFavorite;
use app\model\DashboardLayout;
use app\service\EncryptionService;
use app\service\ExportService;
use app\service\WidgetDataService;
use app\validate\DashboardValidate;
use think\exception\ValidateException;
use think\facade\Db;
use think\Request;
use think\Response;

class Dashboard
{
    private const MANAGER_ROLES = ['admin', 'ops_staff', 'team_lead', 'reviewer'];

    private function requireManager(Request $request): void
    {
        if (!in_array($request->user_role, self::MANAGER_ROLES, true)) {
            throw new ForbiddenException('Manager or admin role required.');
        }
    }

    /** GET /api/dashboard */
    public function index(Request $request): Response
    {
        $this->requireManager($request);
        $layouts = DashboardLayout::where('user_id', $request->user_id)
            ->order('updated_at', 'desc')
            ->select()
            ->toArray();
        return json(['code' => 0, 'data' => $layouts]);
    }

    /** GET /api/dashboard/{id} */
    public function show(Request $request, int $id): Response
    {
        $this->requireManager($request);
        $layout = DashboardLayout::find($id);
        if (!$layout || $layout->user_id !== $request->user_id) {
            throw new NotFoundException('Dashboard not found.');
        }
        return json(['code' => 0, 'data' => $layout->toArray()]);
    }

    /** POST /api/dashboard */
    public function create(Request $request): Response
    {
        $this->requireManager($request);
        $data = $request->post();
        try {
            validate(DashboardValidate::class)->scene('create')->check($data);
        } catch (ValidateException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()], 422);
        }

        $layout = DashboardLayout::create([
            'user_id'     => $request->user_id,
            'name'        => $data['name'] ?? 'My Dashboard',
            'layout_json' => json_encode($data['layout_json']),
        ]);

        return json(['code' => 0, 'data' => $layout->toArray()], 201);
    }

    /** PUT /api/dashboard/{id} */
    public function update(Request $request, int $id): Response
    {
        $this->requireManager($request);
        $layout = DashboardLayout::find($id);
        if (!$layout || $layout->user_id !== $request->user_id) {
            throw new NotFoundException('Dashboard not found.');
        }

        $data = $request->put();
        try {
            validate(DashboardValidate::class)->scene('update')->check($data);
        } catch (ValidateException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()], 422);
        }

        if (isset($data['name'])) {
            $layout->name = $data['name'];
        }
        if (isset($data['layout_json'])) {
            $layout->layout_json = json_encode($data['layout_json']);
        }
        $layout->save();

        return json(['code' => 0, 'data' => $layout->toArray()]);
    }

    /** DELETE /api/dashboard/{id} */
    public function destroy(Request $request, int $id): Response
    {
        $this->requireManager($request);
        $layout = DashboardLayout::find($id);
        if (!$layout || $layout->user_id !== $request->user_id) {
            throw new NotFoundException('Dashboard not found.');
        }
        $layout->delete();
        return json(['code' => 0, 'msg' => 'Deleted.']);
    }

    /** POST /api/dashboard/{id}/favorite */
    public function favorite(Request $request, int $id): Response
    {
        $this->requireManager($request);
        $layout = DashboardLayout::find($id);
        if (!$layout) {
            throw new NotFoundException('Dashboard not found.');
        }

        $existing = DashboardFavorite::where('user_id', $request->user_id)
            ->where('layout_id', $id)
            ->find();

        if ($existing) {
            $existing->delete();
            return json(['code' => 0, 'msg' => 'Unfavorited.']);
        }

        DashboardFavorite::create([
            'user_id'   => $request->user_id,
            'layout_id' => $id,
        ]);
        return json(['code' => 0, 'msg' => 'Favorited.'], 201);
    }

    /** DELETE /api/dashboard/{id}/favorite */
    public function unfavorite(Request $request, int $id): Response
    {
        $this->requireManager($request);
        DashboardFavorite::where('user_id', $request->user_id)
            ->where('layout_id', $id)
            ->delete();
        return json(['code' => 0, 'msg' => 'Unfavorited.']);
    }

    /** GET /api/widgets/data */
    public function widgetData(Request $request): Response
    {
        $this->requireManager($request);
        $data = $request->get();
        try {
            validate(DashboardValidate::class)->scene('widget')->check($data);
        } catch (ValidateException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()], 422);
        }

        $filters = [];
        if (!empty($data['drill_status'])) {
            $filters['drill_status'] = $data['drill_status'];
        }
        $result = WidgetDataService::getWidgetData($data['widget_type'], $filters);
        return json(['code' => 0, 'data' => $result]);
    }

    /** POST /api/dashboard/{id}/export */
    public function export(Request $request, int $id): Response
    {
        $this->requireManager($request);
        $layout = DashboardLayout::find($id);
        if (!$layout || $layout->user_id !== $request->user_id) {
            throw new NotFoundException('Dashboard not found.');
        }

        $data = $request->post();
        try {
            validate(DashboardValidate::class)->scene('export')->check($data);
        } catch (ValidateException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()], 422);
        }

        $format      = $data['format'];
        $widgetData  = [];
        $layoutArray = json_decode($layout->layout_json, true) ?? [];
        foreach ($layoutArray as $widget) {
            $type = $widget['widget_type'] ?? null;
            if (!$type) {
                continue;
            }
            try {
                $items = WidgetDataService::getWidgetData($type);
                foreach ($items as $k => $v) {
                    $widgetData[$k] = is_array($v) ? json_encode($v) : $v;
                }
            } catch (\InvalidArgumentException) {
                // skip unknown widget types
            }
        }

        $filePath = match ($format) {
            'pdf'  => ExportService::exportPdf($widgetData, $layout->name, $request->username),
            'xlsx' => ExportService::exportXlsx($widgetData, $layout->name, $request->username),
            'png'  => ExportService::exportPng($widgetData, $layout->name, $request->username),
        };

        $export = DashboardExport::create([
            'user_id'   => $request->user_id,
            'layout_id' => $id,
            'format'    => $format,
            'file_path' => $filePath,
        ]);

        return json(['code' => 0, 'data' => ['file_path' => $filePath, 'export_id' => $export->id]], 201);
    }

    /** GET /api/users/{id}/sensitive — returns masked sensitive fields (admin only) */
    public function sensitiveFields(Request $request, int $userId): Response
    {
        if ($request->user_role !== 'admin') {
            throw new ForbiddenException('Admin role required.');
        }
        $user = Db::table('users')->where('id', $userId)->find();
        if (!$user) {
            throw new NotFoundException('User not found.');
        }

        $passengerId = null;
        if (!empty($user['passenger_id_enc'])) {
            try {
                $plain = EncryptionService::decrypt($user['passenger_id_enc']);
                $passengerId = EncryptionService::mask($plain);
            } catch (\Throwable) {
                $passengerId = '***';
            }
        }

        return json(['code' => 0, 'data' => ['passenger_id' => $passengerId]]);
    }
}
