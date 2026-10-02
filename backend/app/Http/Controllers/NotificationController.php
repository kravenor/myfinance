<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $items = $this->visible($user)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->present($n));

        return response()->json([
            'data' => $items,
            'unread_count' => $this->unreadCount($user),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->visible($user)->findOrFail($id)->markAsRead();

        return response()->json(['unread_count' => $this->unreadCount($user)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->visible($user)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function destroy(Request $request, string $id): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Nascosta, non cancellata: la dedup la vede ancora e la scansione non la ricrea.
        $notification = $this->visible($user)->findOrFail($id);
        $notification->forceFill(['dismissed_at' => now(), 'read_at' => $notification->read_at ?? now()])->save();

        return response()->noContent();
    }

    /** @return MorphMany<DatabaseNotification, User> */
    private function visible(User $user): MorphMany
    {
        return $user->notifications()->whereNull('dismissed_at');
    }

    private function unreadCount(User $user): int
    {
        return $this->visible($user)->whereNull('read_at')->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $n): array
    {
        /** @var array<string, mixed> $data */
        $data = $n->data;

        return [
            'id' => $n->id,
            'type' => $data['type'] ?? null,
            'level' => $data['level'] ?? null,
            'title' => $data['title'] ?? '',
            'message' => $data['message'] ?? '',
            'url' => $data['url'] ?? null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
