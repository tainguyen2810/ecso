<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Geo\Services\VnAddress;
use App\Modules\Sales\Http\Resources\CustomerAddressResource;
use App\Modules\Sales\Http\Resources\CustomerNoteResource;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\CustomerNote;
use App\Modules\Sales\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Extra delivery addresses and the note timeline of a customer.
 */
class CustomerProfileController extends Controller
{
    public const MAX_ADDRESSES = 20;

    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        if ($customer->addresses()->count() >= self::MAX_ADDRESSES) {
            throw ValidationException::withMessages(['address' => 'Mỗi khách tối đa '.self::MAX_ADDRESSES.' địa chỉ.']);
        }
        $data = $this->address($request);
        $address = new CustomerAddress(array_intersect_key($data, array_flip(['label', 'recipient', 'phone'])));
        app(VnAddress::class)->apply($address, $data);
        $address->customer_id = $customer->id;
        $address->save();

        return (new CustomerAddressResource($address))->response()->setStatusCode(201);
    }

    public function updateAddress(Request $request, Customer $customer, CustomerAddress $address): CustomerAddressResource
    {
        $this->authorize('update', $customer);
        abort_unless($address->customer_id === $customer->id, 404);
        $data = $this->address($request);
        $address->fill(array_intersect_key($data, array_flip(['label', 'recipient', 'phone'])));
        app(VnAddress::class)->apply($address, $data);
        $address->save();

        return new CustomerAddressResource($address);
    }

    public function destroyAddress(Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->authorize('update', $customer);
        abort_unless($address->customer_id === $customer->id, 404);
        $address->delete();

        return response()->json(null, 204);
    }

    public function storeNote(Request $request, Customer $customer): JsonResponse
    {
        // Anyone who can see the customer can log a note (a call, a complaint); only managers edit the profile.
        $this->authorize('view', $customer);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'kind' => ['nullable', Rule::in(CustomerNote::KINDS)]]);
        $data['kind'] ??= 'note';
        $note = new CustomerNote($data);
        $note->forceFill(['customer_id' => $customer->id, 'created_by' => $request->user()->id])->save();

        return (new CustomerNoteResource($note->load('author:id,name')))->response()->setStatusCode(201);
    }

    public function destroyNote(Request $request, Customer $customer, CustomerNote $note): JsonResponse
    {
        abort_unless($note->customer_id === $customer->id, 404);
        if ($note->created_by !== $request->user()->id) {
            $this->authorize('update', $customer);
        }
        $note->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function address(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['required_without:ward_code', 'nullable', 'string', 'max:500'],
            ...VnAddress::rules(),
        ]);
        $phone = PhoneNumber::normalize($data['phone'] ?? null);
        if ($phone !== null && ! PhoneNumber::isValid($phone)) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại không hợp lệ.']);
        }

        return ['phone' => $phone] + $data;
    }
}
