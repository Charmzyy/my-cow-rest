<?php

namespace App\Http\Controllers;

use App\Models\UserPost;
use App\Services\BreedPredictor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Free "quick breed check": one photo in, AI breed guess out (stored for officer review).
class UserPostController extends Controller
{
    public function store(Request $request, BreedPredictor $predictor)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,bmp|max:4096|min:10',
            'cow_name' => 'required|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userId = $request->user()->id;
        $hash = hash_file('sha256', $request->file('image')->getRealPath());

        // Same person, same photo, within a day: return the earlier result instead of a duplicate
        $existing = UserPost::where('user_id', $userId)
            ->where('image_hash', $hash)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->first();
        if ($existing) {
            return response()->json([
                'predictedClass' => $existing->predicted_class,
                'confidence' => $existing->confidence,
                'userpost' => $existing,
                'duplicate' => true,
            ], 200);
        }

        try {
            $image = $request->file('image')->store('public/uploads');
            $prediction = $predictor->predict(storage_path('app/' . $image));

            $userPost = UserPost::create([
                'image' => str_replace('public/', '', $image),
                'image_hash' => $hash,
                'user_id' => $userId,
                'predicted_class' => $prediction['class'],
                'confidence' => $prediction['confidence'],
                'cow_name' => $request->input('cow_name'),
            ]);

            return response()->json([
                'predictedClass' => $prediction['class'],
                'confidence' => $prediction['confidence'],
                'userpost' => $userPost,
            ], 201);
        } catch (\GuzzleHttp\Exception\GuzzleException $th) {
            // The FastAPI model service is down, asleep or unreachable
            report($th);
            return response()->json(['message' => 'The breed prediction service is unavailable right now. Please try again in a few minutes.'], 503);
        } catch (\Throwable $th) {
            report($th);
            return response()->json(['message' => 'Could not process the photo.'], 500);
        }
    }
}
