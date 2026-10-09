<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Holding;
use App\Support\Qr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

// Ear-tag QR codes: a printable A4 sticker sheet for a farm, and looking a cow up from a scanned code.
// The QR holds FRONTEND_URL/c/{code}: the phone's own camera opens the app on that cow, and the
// in-app scanner reads the code from the same URL.
class TagController extends Controller
{
    public function sheet(Request $request, Holding $holding)
    {
        abort_unless($holding->allows($request->user(), 'herd.view'), 403, 'Not Authorized');
        $data = $request->validate(['animal_ids' => ['nullable', 'array', 'max:300'], 'animal_ids.*' => ['uuid']]);

        $animals = $holding->animals()->where('status', 'active')
            ->when($data['animal_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('name')->orderBy('tag_number')
            ->get(['id', 'name', 'tag_number', 'qr_code']);
        abort_if($animals->isEmpty(), 422, 'This farm has no animals to print tags for yet.');

        $base = rtrim(config('app.frontend_url'), '/');
        $tags = $animals->map(fn (Animal $a) => [
            'tag_number' => $a->tag_number,
            'name' => $a->name,
            'code' => $a->qr_code,
            'qr' => Qr::runs("{$base}/c/{$a->qr_code}"),
        ]);

        File::ensureDirectoryExists(storage_path('fonts'));   // dompdf's font cache

        return Pdf::loadView('tag-sheet', ['farm' => $holding, 'tags' => $tags])
            ->setPaper('a4')
            ->download('ear-tags-' . str($holding->name)->slug() . '.pdf');
    }

    /** A scanned or typed code -> the cow, if this person may see her. */
    public function resolve(Request $request, string $code)
    {
        $code = strtoupper(preg_replace('/[\s\-]/', '', $code));
        $animal = Animal::where('qr_code', $code)->first();
        abort_unless($animal, 404, 'No animal has this code. Check it and try again.');
        abort_unless($request->user()->can('view', $animal), 403, 'This animal isn’t on a farm you work on.');

        return response()->json(['animal' => $animal->only(['id', 'name', 'tag_number', 'qr_code', 'holding_id', 'sex'])]);
    }
}
