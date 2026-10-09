<?php

namespace App\Http\Controllers;

use App\Models\Holding;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Farms. Everyone on a farm's team sees it (with their role); owners and managers edit it,
// only the owner removes it. Coordinates are never public.
class HoldingController extends Controller
{
    public function index(Request $request)
    {
        $roles = $request->user()->memberships()->pluck('role', 'holding_id');

        $holdings = Holding::whereIn('id', $roles->keys())
            ->with('county:id,name')
            ->withCount('animals')
            ->orderBy('name')
            ->get()
            ->each(fn (Holding $h) => $h->setAttribute('my_role', $roles[$h->id]));

        return response()->json(['holdings' => $holdings]);
    }

    public function store(Request $request)
    {
        $holding = new Holding($request->validate($this->rules()));
        $holding->owner_id = $request->user()->id;
        $holding->save();   // also makes the creator its owner member (Holding::booted)

        return response()->json(['holding' => $this->present($holding, 'owner')], 201);
    }

    public function update(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'farm.update');

        $holding->update($request->validate($this->rules()));
        $holding->syncAnimals();

        return response()->json(['holding' => $this->present($holding, $holding->roleOf($request->user()))]);
    }

    public function destroy(Request $request, Holding $holding)
    {
        $this->authorizeFarm($request, $holding, 'farm.delete');
        abort_if($holding->animals()->exists(), 422, 'Move this farm’s animals to another farm before removing it.');

        $holding->delete();

        return response()->json(['message' => 'Farm removed']);
    }

    private function present(Holding $holding, ?string $role): Holding
    {
        return $holding->load('county:id,name')->loadCount('animals')->setAttribute('my_role', $role);
    }

    private function authorizeFarm(Request $request, Holding $holding, string $ability): void
    {
        abort_unless($holding->allows($request->user(), $ability), 403, 'Not Authorized');
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
