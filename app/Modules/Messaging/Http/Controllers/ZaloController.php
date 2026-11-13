<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Messaging\Models\ZaloAccount;
use App\Modules\Messaging\Zalo\ZaloException;
use App\Modules\Messaging\Zalo\ZaloService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Cấu hình → Kết nối dịch vụ → Zalo ZNS (C6). Everything here needs settings.manage. */
class ZaloController extends Controller
{
    public function __construct(private readonly ZaloService $zalo, private readonly AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => $this->zalo->present($this->zalo->account())]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:40', 'regex:/^\d+$/'],
            'secret_key' => ['required', 'string', 'max:100'],
            'refresh_token' => ['required', 'string', 'max:1000'],
        ], ['app_id.regex' => 'App ID chỉ gồm chữ số.']);
        $account = $this->zalo->connect($data['app_id'], trim($data['secret_key']), trim($data['refresh_token']));
        $this->audit->record('zalo.connected', $account, ['app_id' => $account->app_id, 'oa' => $account->oa_name]);

        return response()->json(['data' => $this->zalo->present($account)]);
    }

    public function templates(): JsonResponse
    {
        $this->guard();
        $account = $this->connected();
        try {
            $this->zalo->refreshTemplates($account);
        } catch (ZaloException $e) {
            throw ValidationException::withMessages(['zalo' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->zalo->present($account)]);
    }

    public function rules(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.template_id' => ['nullable', 'string', 'max:20'],
            'rules.*.enabled' => ['boolean'],
            'rules.*.params' => ['nullable', 'array'],
            'rules.*.params.*' => ['nullable', 'string', 'max:40'],
        ]);
        $account = $this->zalo->saveRules($this->connected(), $data['rules']);
        $this->audit->record('zalo.rules_updated', $account, ['events' => array_keys(array_filter($account->rules ?? [], fn ($r) => $r['enabled']))]);

        return response()->json(['data' => $this->zalo->present($account)]);
    }

    public function disconnect(): JsonResponse
    {
        $this->guard();
        $account = $this->connected();
        $account->forceFill(['secrets' => null, 'access_expires_at' => null, 'status' => 'disconnected'])->save();
        $this->audit->record('zalo.disconnected', $account, ['app_id' => $account->app_id]);

        return response()->json(['data' => $this->zalo->present($account)]);
    }

    private function connected(): ZaloAccount
    {
        $account = $this->zalo->account();
        if ($account === null || $account->status === 'disconnected') {
            throw ValidationException::withMessages(['zalo' => 'Chưa kết nối Zalo OA.']);
        }

        return $account;
    }

    private function guard(): void
    {
        abort_unless(app(TenantContext::class)->can('settings.manage'), 403);
    }
}
