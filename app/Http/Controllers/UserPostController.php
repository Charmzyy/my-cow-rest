<?php

namespace App\Http\Controllers;

use App\Models\UserPost;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

class UserPostController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,bmp|max:4096|min:10',
                'cow_name' =>'required'
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $userId = $request->user()->id;
    
            // Store the image
            $image = $request->file('image')->store('public/uploads');
    
            // Create a Guzzle client instance
            $client = new Client();
    
            // Prepare the image data and user_id to send to FastAPI
            $imagePath = storage_path('app/' . $image);
            $imageData = file_get_contents($imagePath);
    
            // Send a POST request to your FastAPI endpoint
            $response = $client->post('http://127.0.0.1:8003/predict', [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $imageData,
                        'filename' => basename($imagePath),
                    ],
                    [
                        'name' => 'user_id',
                        'contents' => $userId,
                    ],
                ],
            ]);
    
            // Get the response from FastAPI
            $apiResponse = json_decode($response->getBody(), true);
    
            $predictedClass = $apiResponse['class'] ?? null;
            $confidence = $apiResponse['confidence'] ?? null;
    
            // Create a new UserPost record with prediction details
            $userPost = UserPost::create([
                'image' => str_replace('public/', '', $image),
                'user_id' => $userId,
                'predicted_class' => $predictedClass,
                'confidence' => $confidence,
                'cow_name' => $request->input('cow_name'),
            ]);
    
            return response()->json([
                'predictedClass' => $predictedClass,
                'confidence' => $confidence,
                'userpost' => $userPost // Optional: Return the created UserPost
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([$th->getMessage()]);
        }
    }
    



}


