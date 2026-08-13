<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contact-form inbox. status, assignment and the reply columns are all outside
 * ContactMessage::$fillable on purpose -- they are staff state, never form input
 * -- so every write here goes through forceFill().
 */
class ContactMessageController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        return view('admin.messages.index', [
            'messages' => $this->query($request)->paginate(self::PER_PAGE)->withQueryString(),
            'statuses' => $this->statuses(),
            'counts' => $this->counts(),
        ]);
    }

    public function show(ContactMessage $message): View
    {
        // Opening it is what "read" means; a separate button to mark it read is a
        // button nobody presses, and the unread count on the dashboard would lie.
        if ($message->status === ContactMessage::STATUS_NEW) {
            $message->forceFill(['status' => ContactMessage::STATUS_READ])->save();
        }

        $message->load(['user', 'assignedTo', 'repliedBy']);

        return view('admin.messages.show', ['message' => $message]);
    }

    /**
     * Records the reply against the message and marks it replied.
     *
     * DELIVERY IS NOT WIRED UP HERE. This writes the audit trail; sending the
     * mail is a Mailable and a queue, and a half-built send from a controller
     * would fail silently on a host with no mailer configured. The saved body is
     * what a support agent quotes back, and it is ready for a notification to
     * pick up.
     */
    public function reply(Request $request, ContactMessage $message): RedirectResponse
    {
        $validated = $request->validate([
            'reply_body' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        $message->forceFill([
            'reply_body' => $validated['reply_body'],
            'replied_at' => now(),
            'replied_by' => $request->user()->id,
            'status' => ContactMessage::STATUS_REPLIED,
        ])->save();

        activity()
            ->performedOn($message)
            ->causedBy($request->user())
            ->log("Replied to contact message from {$message->email}");

        return back()->with('status', 'Reply saved against the message.');
    }

    /** @return Builder<ContactMessage> */
    private function query(Request $request): Builder
    {
        $query = ContactMessage::query()->with(['user', 'assignedTo'])->latest('id');

        if (in_array($status = (string) $request->query('status'), $this->statuses(), true)) {
            $query->status($status);
        }

        if (($term = trim((string) $request->query('q'))) !== '') {
            $query->where(function (Builder $scoped) use ($term): void {
                $scoped->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%");
            });
        }

        return $query;
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return [
            ContactMessage::STATUS_NEW,
            ContactMessage::STATUS_READ,
            ContactMessage::STATUS_REPLIED,
            ContactMessage::STATUS_SPAM,
            ContactMessage::STATUS_ARCHIVED,
        ];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = ContactMessage::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $all = [];

        foreach ($this->statuses() as $status) {
            $all[$status] = (int) ($counts[$status] ?? 0);
        }

        return $all;
    }
}
