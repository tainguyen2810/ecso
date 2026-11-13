<?php

namespace App\Modules\Messaging\Zalo;

use App\Modules\Messaging\Models\ZaloAccount;
use App\Modules\Messaging\Models\ZaloMessage;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Customer;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Zalo ZNS (C6): connects the shop's OA, keeps the list of templates Zalo approved, and sends the chosen template on
 * each order event, filling it from the order. One message per order and event, whatever retries happen.
 */
class ZaloService
{
    /** Order events a template can be chosen for. */
    public const EVENTS = [
        'confirmed' => 'Đơn đã xác nhận',
        'shipped' => 'Đã giao cho vận chuyển',
        'delivered' => 'Giao thành công',
        'payment_reminder' => 'Nhắc thanh toán (đơn còn nợ quá 3 ngày)',
    ];

    /** What EzSell can put in a template parameter. */
    public const FIELDS = [
        'customer_name' => 'Tên khách',
        'order_code' => 'Mã đơn',
        'order_date' => 'Ngày đặt',
        'total' => 'Tổng tiền',
        'amount_due' => 'Còn phải trả',
        'carrier' => 'Hãng vận chuyển',
        'tracking' => 'Mã vận đơn',
        'phone' => 'Số điện thoại khách',
        'shop_name' => 'Tên cửa hàng',
    ];

    public function __construct(private readonly ZaloClient $client) {}

    public function account(): ?ZaloAccount
    {
        return ZaloAccount::query()->first();
    }

    /** Checks the keys with Zalo, then saves the connection with the OA's name and its templates. */
    public function connect(string $appId, string $secretKey, string $refreshToken): ZaloAccount
    {
        try {
            $pair = $this->client->exchange($appId, $secretKey, $refreshToken);
        } catch (ZaloException $e) {
            throw ValidationException::withMessages(['refresh_token' => $e->getMessage()]);
        }
        $account = $this->account() ?? new ZaloAccount;
        $account->forceFill([
            'app_id' => $appId,
            'secrets' => ['secret_key' => $secretKey, 'refresh_token' => $pair['refresh_token'], 'access_token' => $pair['access_token']],
            'access_expires_at' => now()->addSeconds(max(600, $pair['expires_in'] - 300)),
            'status' => ZaloAccount::CONNECTED,
            'last_error' => null,
        ])->save();
        try {
            $oa = $this->client->get($account, ZaloClient::OPENAPI.'/v2.0/oa/getoa');
            $account->forceFill(['oa_id' => isset($oa['oa_id']) ? (string) $oa['oa_id'] : null, 'oa_name' => isset($oa['name']) ? (string) $oa['name'] : null])->save();
            $this->refreshTemplates($account);
        } catch (ZaloException $e) {
            $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }

        return $account;
    }

    /** Reads the OA's ZNS templates and the parameters of each one Zalo approved. */
    public function refreshTemplates(ZaloAccount $account): ZaloAccount
    {
        $list = $this->client->get($account, ZaloClient::BUSINESS.'/template/all', ['offset' => 0, 'limit' => 100]);
        $templates = [];
        foreach (array_is_list($list) ? $list : [] as $t) {
            $id = (string) ($t['templateId'] ?? '');
            if ($id === '') {
                continue;
            }
            $status = (string) ($t['status'] ?? '');
            $params = [];
            if (strtoupper($status) === 'ENABLE') {
                $info = $this->client->get($account, ZaloClient::BUSINESS.'/template/info/v2', ['template_id' => $id]);
                foreach ((array) ($info['listParams'] ?? []) as $p) {
                    $params[] = ['name' => (string) ($p['name'] ?? ''), 'required' => (bool) ($p['require'] ?? false), 'type' => isset($p['type']) ? (string) $p['type'] : null, 'max' => isset($p['maxLength']) ? (int) $p['maxLength'] : null];
                }
            }
            $templates[] = ['id' => $id, 'name' => (string) ($t['templateName'] ?? $id), 'status' => $status, 'params' => $params];
        }
        $account->forceFill(['templates' => $templates, 'last_error' => null])->save();

        return $account;
    }

    /**
     * @param  array<string, array{template_id?: string|null, enabled?: bool, params?: array<string, string>}>  $rules
     */
    public function saveRules(ZaloAccount $account, array $rules): ZaloAccount
    {
        $templates = collect($account->templates ?? [])->keyBy('id');
        $clean = [];
        foreach ($rules as $event => $rule) {
            if (! isset(self::EVENTS[$event])) {
                continue;
            }
            $templateId = filled($rule['template_id'] ?? null) ? (string) $rule['template_id'] : null;
            $template = $templateId !== null ? $templates->get($templateId) : null;
            if ($templateId !== null && $template === null) {
                throw ValidationException::withMessages(["rules.{$event}.template_id" => 'Mẫu này không có trong OA. Bấm "Tải lại mẫu từ Zalo".']);
            }
            if ($template !== null && ! empty($rule['enabled']) && strtoupper((string) $template['status']) !== 'ENABLE') {
                throw ValidationException::withMessages(["rules.{$event}.template_id" => "Mẫu {$template['name']} chưa được Zalo duyệt."]);
            }
            $params = [];
            foreach ($template['params'] ?? [] as $p) {
                $field = (string) ($rule['params'][$p['name']] ?? self::guess($p['name']) ?? '');
                if ($field !== '' && ! isset(self::FIELDS[$field])) {
                    throw ValidationException::withMessages(["rules.{$event}.params" => 'Trường điền vào mẫu không hợp lệ.']);
                }
                if ($field === '' && $p['required'] && ! empty($rule['enabled'])) {
                    throw ValidationException::withMessages(["rules.{$event}.params" => "Chọn nội dung cho \"{$p['name']}\" của mẫu {$template['name']}."]);
                }
                $params[$p['name']] = $field;
            }
            $clean[$event] = ['template_id' => $templateId, 'enabled' => (bool) ($rule['enabled'] ?? false) && $templateId !== null, 'params' => $params];
        }
        $account->forceFill(['rules' => $clean])->save();

        return $account;
    }

    /** The field a parameter most likely wants, from its name ("customer_name", "order_code", ...). */
    public static function guess(string $param): ?string
    {
        $p = strtolower($param);

        return match (true) {
            isset(self::FIELDS[$p]) => $p,
            str_contains($p, 'name') && ! str_contains($p, 'shop') => 'customer_name',
            str_contains($p, 'track') || str_contains($p, 'bill') => 'tracking',
            str_contains($p, 'carrier') || str_contains($p, 'ship') => 'carrier',
            str_contains($p, 'order') || str_contains($p, 'code') => 'order_code',
            str_contains($p, 'due') || str_contains($p, 'debt') => 'amount_due',
            str_contains($p, 'total') || str_contains($p, 'price') || str_contains($p, 'amount') => 'total',
            str_contains($p, 'date') || str_contains($p, 'time') => 'order_date',
            str_contains($p, 'phone') => 'phone',
            str_contains($p, 'shop') => 'shop_name',
            default => null,
        };
    }

    /** The template chosen for the event, when it is on. */
    public function ruleFor(ZaloAccount $account, string $event): ?array
    {
        $rule = $account->rules[$event] ?? null;

        return is_array($rule) && $rule['enabled'] && filled($rule['template_id']) ? $rule : null;
    }

    /** Sends the event's message for the order once. Returns the log row, or null when nothing was to be sent. */
    public function send(Order $order, string $event): ?ZaloMessage
    {
        $account = $this->account();
        if ($account === null || $account->status === 'disconnected') {
            return null;
        }
        $rule = $this->ruleFor($account, $event);
        $phone = self::phone((string) $order->buyer_phone);
        if ($rule === null || $phone === null) {
            return null;
        }
        if (ZaloMessage::query()->where('order_id', $order->id)->where('event', $event)->where('status', ZaloMessage::SENT)->exists()) {
            return null;
        }
        $template = collect($account->templates ?? [])->firstWhere('id', $rule['template_id']);
        $data = [];
        foreach ($rule['params'] as $param => $field) {
            if ($field !== '') {
                $data[$param] = $this->value($order, $field);
            }
        }
        $log = new ZaloMessage([
            'order_id' => $order->id, 'event' => $event, 'template_id' => (string) $rule['template_id'], 'template_name' => $template['name'] ?? null,
            'phone_masked' => substr($phone, 0, 4).'***'.substr($phone, -3), 'buyer_name' => mb_substr((string) $this->value($order, 'customer_name'), 0, 150),
        ]);
        try {
            $answer = $this->client->post($account, ZaloClient::BUSINESS.'/message/template', [
                'phone' => $phone, 'template_id' => (string) $rule['template_id'], 'template_data' => $data, 'tracking_id' => $order->ulid.'-'.$event,
            ]);
            $log->fill(['status' => ZaloMessage::SENT, 'msg_id' => isset($answer['msg_id']) ? (string) $answer['msg_id'] : null]);
        } catch (ZaloException $e) {
            $log->fill(['status' => ZaloMessage::FAILED, 'error' => mb_substr($e->getMessage(), 0, 255)]);
            Log::info('zalo.zns.failed', ['order' => $order->id, 'event' => $event, 'code' => $e->getCode()]);
        }
        $log->save();

        return $log;
    }

    /** 0912345678 / +84 912 345 678 → 84912345678; null when it is not a Vietnamese mobile number. */
    public static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '84')) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^0[35789]\d{8}$/', $digits) ? '84'.substr($digits, 1) : null;
    }

    private function value(Order $order, string $field): string
    {
        $money = fn ($v) => number_format((float) $v, 0, ',', '.');

        return match ($field) {
            'customer_name' => (string) ($order->buyer_name ?: ($order->customer_id ? Customer::query()->whereKey($order->customer_id)->value('name') : '') ?: 'Quý khách'),
            'order_code' => (string) $order->external_order_id,
            'order_date' => ($order->placed_at ?? $order->created_at)?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') ?? '',
            'total' => $money($order->total),
            'amount_due' => $money(max(0, (float) $order->total - (float) $order->paid_amount - (float) $order->refunded_amount)),
            'carrier' => (string) ($order->carrier ?? ''),
            'tracking' => (string) ($order->tracking_number ?? ''),
            'phone' => (string) $order->buyer_phone,
            'shop_name' => app(TenantContext::class)->tenant()->name,
            default => '',
        };
    }

    /** The settings page: account (no secrets), templates, rules, events and fields, last messages. */
    public function present(?ZaloAccount $account): array
    {
        return [
            'account' => $account ? [
                'id' => $account->ulid, 'app_id' => $account->app_id, 'oa_name' => $account->oa_name, 'status' => $account->status, 'last_error' => $account->last_error,
                'templates' => $account->templates ?? [], 'rules' => (object) ($account->rules ?? []),
            ] : null,
            'events' => self::EVENTS,
            'fields' => self::FIELDS,
            'messages' => $account ? ZaloMessage::query()->with('order:id,ulid,external_order_id')->latest('id')->limit(30)->get()->map(fn (ZaloMessage $m) => [
                'id' => $m->id, 'event' => $m->event, 'template_name' => $m->template_name, 'phone' => $m->phone_masked, 'buyer_name' => $m->buyer_name,
                'status' => $m->status, 'error' => $m->error, 'order' => $m->order ? ['id' => $m->order->ulid, 'number' => $m->order->external_order_id] : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all() : [],
        ];
    }
}
