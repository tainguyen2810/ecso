<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderQuery;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\EzChatLink;
use App\Modules\Sales\Services\EzChatInbox;
use App\Modules\Sales\Services\EzChatInboxException;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Tin nhắn": the EzChat inbox inside this app, its menu badge, and the column of this app's data beside a chat. */
class EzChatInboxController extends Controller
{
    public function __construct(private readonly EzChatInbox $inbox, private readonly TenantContext $context) {}

    /** Whether the menu shows "Tin nhắn", and its badge. */
    public function show(Request $request): JsonResponse
    {
        $reason = $this->inbox->unavailable($this->context->tenant());

        return response()->json(['data' => [
            'available' => $reason === null,
            'reason' => $reason,
            'unread' => $reason === null ? $this->inbox->unread($this->context->tenant(), $this->user($request)) : null,
        ]]);
    }

    /** A fresh address for the frame (one-time ticket); asked again when EzChat's session in the frame ends. */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['conversation' => ['nullable', 'string', 'max:64']]);
        $origin = $this->origin($request);
        try {
            return response()->json(['data' => $this->inbox->open($this->context->tenant(), $this->user($request), $origin, $data['conversation'] ?? null)]);
        } catch (EzChatInboxException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    /**
     * The chat open in the frame: this app's customer with that phone (or email), their latest orders, and the
     * EzChat sales channel for "Tạo đơn từ chat này". Nothing is shown to members who cannot see customers.
     */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:200'],
        ]);
        $phone = PhoneNumber::normalize($data['phone'] ?? null);
        $email = Customer::emailKey($data['email'] ?? null);
        $canCustomers = $this->context->can('customers.view');
        $customer = null;
        if ($canCustomers && ($phone !== null || $email !== null)) {
            $customer = Customer::query()->withStats()->with('customerGroup')
                ->where(function ($q) use ($phone, $email) {
                    if ($phone !== null) {
                        $q->orWhere('phone_hash', PhoneNumber::hash($phone));
                    }
                    if ($email !== null) {
                        $q->orWhere('email_key', $email);
                    }
                })
                ->orderByDesc('id')->first();
        }

        $orders = [];
        if ($this->context->can('orders.view') && ($customer !== null || $phone !== null)) {
            $query = OrderQuery::visible(Order::query())->with('channel');
            $query->where(function ($q) use ($customer, $phone) {
                if ($customer !== null) {
                    $q->orWhere('customer_id', $customer->id);
                }
                if ($phone !== null) {
                    $q->orWhere('buyer_phone_hash', PhoneNumber::hash($phone));
                }
            });
            $orders = $query->orderByDesc('placed_at')->orderByDesc('id')->limit(5)->get()->map(fn (Order $o) => [
                'id' => $o->ulid,
                'code' => $o->code ?: $o->external_order_id,
                'status' => $o->status,
                'marketplace' => $o->marketplace,
                'raw_status' => $o->raw_status ?? null,
                'total' => (float) $o->total,
                'placed_at' => $o->placed_at?->toIso8601String(),
                'channel' => $o->channel?->name,
            ])->all();
        }

        $link = EzChatLink::query()->with('channel')->first();

        return response()->json(['data' => [
            'customer' => $customer ? [
                'id' => $customer->ulid,
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'group' => $customer->customerGroup?->name,
                'orders_count' => (int) $customer->orders_count,
                'total_spent' => (float) $customer->total_spent,
                'debt' => (float) $customer->debt,
            ] : null,
            'orders' => $orders,
            'channel_id' => $link?->channel?->is_active ? $link->channel->ulid : null,
        ]]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** The page's own address (the browser sends it with every POST), else this app's configured address. */
    private function origin(Request $request): string
    {
        $origin = (string) $request->headers->get('origin', '');
        if (preg_match('#^https?://[^/\s]+$#', $origin) !== 1) {
            $url = parse_url((string) config('app.url'));
            $origin = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? 'localhost').(isset($url['port']) ? ':'.$url['port'] : '');
        }

        return $origin;
    }
}
