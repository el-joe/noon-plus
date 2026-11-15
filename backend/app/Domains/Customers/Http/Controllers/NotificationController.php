<?php

namespace App\Domains\Customers\Http\Controllers;

use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Customers\Support\CustomerNotifications;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's notification centre (G-W29): newest first, optional
 * `unread=1`, unread count, mark some or all read. Own rows only, in the
 * market's country.
 */
class NotificationController extends Controller
{
    public function __construct(private CustomerNotifications $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $userId = $request->user()->id;
        $page = $this->notifications->forUser($userId)
            ->when($filters['unread'] ?? false, fn ($query) => $query->whereNull('read_at'))
            ->latest('id')->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(collect($page->items())->map(fn (CustomerNotification $notification): array => $notification->toPublicArray())->all(), [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'unread' => $this->notifications->unreadCount($userId),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $this->notifications->unreadCount($request->user()->id)]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $userId = $request->user()->id;
        $row = $this->notifications->forUser($userId)->findOrFail($notification);
        $this->notifications->markRead($userId, [$row->id]);

        return ApiResponse::success($row->refresh()->toPublicArray() + ['unread' => $this->notifications->unreadCount($userId)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer']])['ids'] ?? null;
        $marked = $this->notifications->markRead($userId, $ids === null ? null : array_map('intval', $ids));

        return ApiResponse::success(['marked' => $marked, 'unread' => $this->notifications->unreadCount($userId)]);
    }
}
