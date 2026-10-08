<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\Holding;
use App\Services\BreedPredictor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

// A farmer's herd: register animals, edit their records and attach guided photos.
class AnimalController extends Controller
{
    public function index(Request $request)
    {
        $animals = $request->user()->animals()
            ->with(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate'])
            ->latest()
            ->get();

        return response()->json(['animals' => $animals]);
    }

    public function show(Animal $animal)
    {
        $this->authorize('view', $animal);

        return response()->json(['animal' => $animal->load(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate'])]);
    }

    public function store(Request $request)
    {
        $data = array_filter($request->validate($this->rules()), fn ($v, $k) => $k !== 'species' || $v !== null, ARRAY_FILTER_USE_BOTH);

        $animal = new Animal($data);
        $animal->owner_id = $request->user()->id;
        $this->placeOnFarm($request, $animal, $data);
        $animal->save();

        return response()->json(['animal' => $animal->load('county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate')], 201);
    }

    public function update(Request $request, Animal $animal)
    {
        $this->authorize('update', $animal);

        $data = array_filter($request->validate($this->rules($animal)), fn ($v, $k) => $k !== 'species' || $v !== null, ARRAY_FILTER_USE_BOTH);
        $animal->fill($data);
        $this->placeOnFarm($request, $animal, $data);
        $animal->save();

        return response()->json(['animal' => $animal->load(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate'])]);
    }

    public function destroy(Animal $animal)
    {
        $this->authorize('delete', $animal);
        abort_if(
            $animal->certificationRequests()->whereIn('status', \App\Models\CertificationRequest::OPEN)->exists(),
            422,
            'Cancel this animal’s certification request before removing it.'
        );

        // Soft delete: the record (and its tag number) stays for audit; it just leaves the herd
        $animal->delete();

        return response()->json(['message' => 'Animal removed']);
    }

    public function uploadPhoto(Request $request, Animal $animal, BreedPredictor $predictor)
    {
        $this->authorize('update', $animal);

        $request->validate([
            'angle' => ['required', Rule::in(AnimalPhoto::ANGLES)],
            'image' => 'required|image|mimes:jpeg,png,jpg|max:4096',
        ]);

        $angle = $request->input('angle');
        $hash = hash_file('sha256', $request->file('image')->getRealPath());

        // A photo can only ever document one animal, and one angle of it
        $reused = AnimalPhoto::where('image_hash', $hash)->first();
        if ($reused && !($reused->animal_id === $animal->id && $reused->angle === $angle)) {
            $message = $reused->animal_id === $animal->id
                ? 'This photo is already used for another angle of this animal. Please take a separate photo.'
                : 'This photo is already registered to a different animal.';
            return response()->json(['message' => $message, 'errors' => ['image' => [$message]]], 422);
        }
        if ($reused) {
            // Exact same upload for the same slot (double tap / retry): nothing to do
            return response()->json(['photo' => $reused, 'animal' => $animal->load(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate']), 'duplicate' => true]);
        }

        $path = $request->file('image')->store("public/animals/{$animal->id}");

        $aiBreed = null;
        $aiConfidence = null;
        $aiUnavailable = false;
        if (in_array($angle, AnimalPhoto::AI_ANGLES, true)) {
            try {
                $prediction = $predictor->predict(storage_path('app/' . $path));
                $aiBreed = $prediction['class'];
                $aiConfidence = $prediction['confidence'];
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                // Keep the photo; the breed check can be re-run later
                report($e);
                $aiUnavailable = true;
            }
        }

        // Re-uploading an angle replaces the previous photo for it
        $existing = $animal->photos()->where('angle', $angle)->first();
        if ($existing) {
            Storage::delete('public/' . $existing->path);
        }

        $photo = AnimalPhoto::updateOrCreate(
            ['animal_id' => $animal->id, 'angle' => $angle],
            [
                'path' => str_replace('public/', '', $path),
                'image_hash' => $hash,
                'ai_breed' => $aiBreed,
                'ai_confidence' => $aiConfidence,
            ],
        );

        $animal->refreshAiSummary();

        return response()->json([
            'photo' => $photo,
            'animal' => $animal->fresh()->load(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate']),
            'ai_unavailable' => $aiUnavailable,
        ], 201);
    }

    public function deletePhoto(Animal $animal, AnimalPhoto $photo)
    {
        $this->authorize('update', $animal);
        abort_unless($photo->animal_id === $animal->id, 404);

        Storage::delete('public/' . $photo->path);
        $photo->delete();
        $animal->refreshAiSummary();

        return response()->json(['animal' => $animal->fresh()->load(['county:id,name', 'holding:id,name', 'photos', 'latestRequest.officer:id,fullname', 'latestRequest.certificate'])]);
    }

    /** With a farm chosen, the animal takes the farm's location (the request's location fields are ignored). */
    private function placeOnFarm(Request $request, Animal $animal, array $data): void
    {
        if (empty($data['holding_id'])) {
            return;
        }
        $holding = Holding::find($data['holding_id']);
        abort_unless($holding && $holding->owner_id === $request->user()->id, 422, 'Choose one of your own farms.');
        $animal->placeAt($holding);
    }

    private function rules(?Animal $animal = null): array
    {
        $text = fn (int $max) => ['nullable', 'string', "max:$max"];

        return [
            'species' => ['nullable', Rule::in(Animal::SPECIES)],
            'holding_id' => ['nullable', 'uuid'],
            'tag_number' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \/\-]*$/',
                Rule::unique('animals', 'tag_number')->ignore($animal?->id),
            ],
            'national_id' => $text(60),
            'name' => $text(80),
            'sex' => ['required', Rule::in(['female', 'male'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1990-01-01'],
            'age_estimate_months' => ['nullable', 'integer', 'between:0,360'],
            'colour' => $text(120),
            'purpose' => ['nullable', Rule::in(['dairy', 'beef', 'dual'])],
            'breed_claimed' => $text(60),

            'sire_name' => $text(80),
            'sire_tag' => $text(40),
            'dam_name' => $text(80),
            'dam_tag' => $text(40),
            'ai_straw_code' => $text(60),

            'weight_kg' => ['nullable', 'numeric', 'between:10,2000'],
            'body_condition_score' => ['nullable', 'numeric', 'between:1,5'],
            'lactation_number' => ['nullable', 'integer', 'between:0,20'],
            'milk_litres_per_day' => ['nullable', 'numeric', 'between:0,100'],

            // Older clients send the location directly; current ones choose a farm
            'county_id' => ['required_without:holding_id', 'nullable', 'integer', 'exists:counties,id'],
            'sub_county' => $text(80),
            'ward' => $text(80),
            'village' => $text(80),
            // Kenya's bounding box, with a little margin
            'latitude' => ['nullable', 'numeric', 'between:-5,6', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:33,42.5', 'required_with:latitude'],
        ];
    }
}
