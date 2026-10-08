<?php

namespace App\Http\Controllers;

use App\Models\Holding;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// A farmer's farms. Only the owner sees or edits them; coordinates are never public.
class HoldingController extends Controller
{
    public function index(Request $request)
    {
        $holdings = Holding::where('owner_id', $request->user()->id)
            ->with('county:id,name')
            ->withCount('animals')
            ->orderBy('name')
            ->get();

        return response()->json(['holdings' => $holdings]);
    }

    public function store(Request $request)
    {
        $holding = new Holding($request->validate($this->rules()));
        $holding->owner_id = $request->user()->id;
        $holding->save();

        return response()->json(['holding' => $holding->load('county:id,name')->loadCount('animals')], 201);
    }

    public function update(Request $request, Holding $holding)
    {
        $this->authorizeOwner($request, $holding);

        $holding->update($request->validate($this->rules()));
        $holding->syncAnimals();

        return response()->json(['holding' => $holding->load('county:id,name')->loadCount('animals')]);
    }

    public function destroy(Request $request, Holding $holding)
    {
        $this->authorizeOwner($request, $holding);
        abort_if($holding->animals()->exists(), 422, 'Move this farm’s animals to another farm before removing it.');

        $holding->delete();

        return response()->json(['message' => 'Farm removed']);
    }

    private function authorizeOwner(Request $request, Holding $holding): void
    {
        abort_unless($holding->owner_id === $request->user()->id, 403, 'Not Authorized');
    }

    private function rules(): array
    {
        $text = fn (int $max) => ['nullable', 'string', "max:$max"];

        return [
            'name' => ['required', 'string', 'max:80'],
            'county_id' => ['required', 'integer', 'exists:counties,id'],
            'sub_county' => $text(80),
            'ward' => $text(80),
            'village' => $text(80),
            // Kenya's bounding box, with a little margin
            'latitude' => ['nullable', 'numeric', 'between:-5,6', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:33,42.5', 'required_with:latitude'],
            'location_accuracy_m' => ['nullable', 'integer', 'between:0,65000'],
            'location_source' => ['nullable', Rule::in(['gps', 'map'])],
        ];
    }
}
