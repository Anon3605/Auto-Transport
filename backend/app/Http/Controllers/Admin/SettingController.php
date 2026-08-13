<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Typed key/value site config, grouped the way it is stored. Rows are edited, not
 * created: a key nothing reads is dead weight, and the seeder owns the set.
 */
class SettingController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'settings' => Setting::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->groupBy('group'),
        ]);
    }

    /**
     * Writes through Setting::putValue(), which encodes each value according to
     * its own `type` column -- ints as digits, bools as 1/0, json re-encoded from
     * the decoded structure, secrets through Crypt.
     */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $rows = $request->rows();
        $saved = 0;

        DB::transaction(function () use ($request, $rows, &$saved): void {
            foreach ($request->submitted() as $id => $raw) {
                $setting = $rows->get($id);

                if ($setting === null) {
                    continue;
                }

                // A blank encrypted field means "leave the stored secret alone".
                // Writing '' would silently break an integration and leave no
                // way to tell an emptied key from an unset one.
                if ($setting->type === 'encrypted' && ($raw === null || $raw === '')) {
                    continue;
                }

                Setting::putValue($setting->group, $setting->key, $this->decode($setting, $raw), $setting->type);
                $saved++;
            }
        });

        // putValue() forgets the key on every write; doing it once more after the
        // transaction commits is what makes the guarantee independent of how many
        // rows were touched, including zero.
        Cache::forget(Setting::PUBLIC_CACHE_KEY);

        return redirect()
            ->route('admin.settings.index')
            ->with('status', $saved === 1 ? '1 setting saved.' : "{$saved} settings saved.");
    }

    /**
     * Turns the posted string into the shape putValue() expects for that type.
     * Everything arrives as a string from an HTML form; only json needs decoding
     * first, because putValue() re-encodes it.
     */
    private function decode(Setting $setting, ?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            // bool is the exception: an unchecked box is false, not "unset". The
            // form posts a hidden 0 for it, so this only catches a stripped POST.
            return $setting->type === 'bool' ? false : null;
        }

        return match ($setting->type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }
}
