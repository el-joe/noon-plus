<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Domains\Panels\Support\PanelNotifications;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * QC-041: the signed-in person's notifications in this panel and country —
 * open one (marks it read, then follows its link) or mark all read.
 */
class PanelNotificationController extends Controller
{
    public function __construct(private PanelNotifications $notifications) {}

    public function index(Request $request, string $panel): View
    {
        $unreadOnly = $request->boolean('unread');

        return view('panels.shared.notifications', [
            'notifications' => $this->notifications->forUser($request->user()->id, $panel)
                ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
                ->latest('id')->paginate(25)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'panelKey' => $panel,
        ]);
    }

    public function read(Request $request): RedirectResponse
    {
        // Route parameters are read by name: the `panel` default follows the URI parameter.
        $panel = (string) $request->route('panel');
        $notification = (int) $request->route('notification');
        $row = $this->notifications->forUser($request->user()->id, $panel)->findOrFail($notification);
        $this->notifications->markRead($request->user()->id, $panel, [$row->id]);

        return $row->link !== null && str_starts_with($row->link, '/') && ! str_starts_with($row->link, '//')
            ? redirect($row->link)
            : back();
    }

    public function readAll(Request $request, string $panel): RedirectResponse
    {
        $this->notifications->markRead($request->user()->id, $panel);

        return back()->with('status', __('All notifications are marked as read.'));
    }
}
