<?php

use App\Http\Controllers\AdminCertificationController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminOfficerController;
use App\Http\Controllers\AdminStatsController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\AnimalEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificationRequestController;
use App\Http\Controllers\FarmMemberController;
use App\Http\Controllers\HoldingController;
use App\Http\Controllers\MilkController;
use App\Http\Controllers\MilkDeliveryController;
use App\Http\Controllers\OfficerApplicationController;
use App\Http\Controllers\OfficerReviewController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserPostController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;


// Healthcheck for Docker: 200 when the app can reach the database, 503 otherwise
Route::get('health', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['status' => 'ok']);
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error', 'db' => $e->getMessage()], 503);
    }
});


// Public auth routes. Throttled per IP (requests per minute) against password guessing
// and signup / reset-email spam; over the limit = HTTP 429.
Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login')->middleware('throttle:10,1');
    Route::post('register', 'register')->middleware('throttle:5,1');
    Route::post('forgot/password', 'forgot')->middleware('throttle:5,1');
    Route::post('reset/password', 'reset')->middleware('throttle:10,1');
    // Farm workers: phone + PIN. A 4-digit PIN is guessable, so this is throttled hardest.
    Route::post('login/pin', 'loginWithPin')->middleware('throttle:5,1');
});


// Public certificate check: what the QR code on a certificate opens. Throttled against code guessing.
Route::get('verify/{code}', [CertificateController::class, 'verify'])->middleware('throttle:30,1');


// Any signed-in user
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('/', [AuthController::class, 'updateProfile']); // update phone / avatar

    // Owner, certifying officer or admin (checked in the controller)
    Route::get('certificates/{certificate}/pdf', [CertificateController::class, 'pdf']);
});


// Platform admins
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('admin/users', [AdminController::class, 'users']);
    Route::get('admin/{status?}', [AdminController::class, 'getPosts'])
        ->where('status', 'unverified|verified');
    Route::put('admin/{id}/accept', [AdminController::class, 'accept']);
    Route::put('admin/{id}/reject', [AdminController::class, 'reject']);
    Route::delete('admin/{id}/delete', [AdminController::class, 'delete']);

    // Officer verification
    Route::get('admin/officers', [AdminOfficerController::class, 'index']);
    Route::put('admin/officers/{officer}/approve', [AdminOfficerController::class, 'approve']);
    Route::put('admin/officers/{officer}/reject', [AdminOfficerController::class, 'reject']);
    Route::put('admin/officers/{officer}/suspend', [AdminOfficerController::class, 'suspend']);

    // Overview numbers
    Route::get('admin/stats', [AdminStatsController::class, 'index']);

    // Certification requests: see all (paged), assign to an officer, or auto-assign by county
    Route::get('admin/certification-requests', [AdminCertificationController::class, 'index']);
    Route::post('admin/certification-requests/auto-assign', [AdminCertificationController::class, 'autoAssign']);
    Route::put('admin/certification-requests/{certificationRequest}/assign', [AdminCertificationController::class, 'assign']);

    // Certificates: issue for requests approved before v6, revoke
    Route::post('admin/certification-requests/{certificationRequest}/certificate', [CertificateController::class, 'issue']);
    Route::put('admin/certificates/{certificate}/revoke', [CertificateController::class, 'revoke']);
});

// Verified officers (role officer): their review queue and decisions
Route::middleware(['auth:sanctum', 'role:officer'])->prefix('officer')->group(function () {
    Route::get('requests', [OfficerReviewController::class, 'index']);
    Route::get('requests/{certificationRequest}', [OfficerReviewController::class, 'show']);
    Route::put('requests/{certificationRequest}/start', [OfficerReviewController::class, 'start']);
    Route::put('requests/{certificationRequest}/approve', [OfficerReviewController::class, 'approve']);
    Route::put('requests/{certificationRequest}/needs-info', [OfficerReviewController::class, 'needsInfo']);
    Route::put('requests/{certificationRequest}/reject', [OfficerReviewController::class, 'reject']);
});


// Reference data for forms
Route::get('counties', fn () => \App\Models\County::orderBy('name')->get());

// Anyone on a farm's team: farmers (owners / managers) and farm workers.
// What each may do on a given farm is checked per farm (FarmMember::ABILITIES via AnimalPolicy / Holding::allows).
Route::middleware(['auth:sanctum', 'role:farmer,worker'])->group(function () {
    Route::get('holdings', [HoldingController::class, 'index']);            // the farms I'm on, with my role
    Route::get('animals', [AnimalController::class, 'index']);
    Route::get('animals/{animal}', [AnimalController::class, 'show']);

    // Health / breeding / growth records
    Route::get('animals/{animal}/events', [AnimalEventController::class, 'index']);
    Route::post('animals/{animal}/events', [AnimalEventController::class, 'store']);
    Route::delete('animals/{animal}/events/{event}', [AnimalEventController::class, 'destroy']);

    // Milk: the session sheet, saving it, and the numbers behind the charts
    Route::get('holdings/{holding}/milk/session', [MilkController::class, 'session']);
    Route::post('holdings/{holding}/milk/session', [MilkController::class, 'record']);
    Route::get('holdings/{holding}/milk/summary', [MilkController::class, 'summary']);
    Route::get('animals/{animal}/milk', [MilkController::class, 'cow']);

    // Where the milk went, and who buys it
    Route::get('holdings/{holding}/milk/deliveries', [MilkDeliveryController::class, 'index']);
    Route::post('holdings/{holding}/milk/deliveries', [MilkDeliveryController::class, 'store']);
    Route::put('holdings/{holding}/milk/deliveries/{delivery}', [MilkDeliveryController::class, 'update']);
    Route::delete('holdings/{holding}/milk/deliveries/{delivery}', [MilkDeliveryController::class, 'destroy']);
    Route::get('holdings/{holding}/milk/buyers', [MilkDeliveryController::class, 'buyers']);
    Route::post('holdings/{holding}/milk/buyers', [MilkDeliveryController::class, 'storeBuyer']);
    Route::put('holdings/{holding}/milk/buyers/{buyer}', [MilkDeliveryController::class, 'updateBuyer']);

    // Ear-tag QR codes: printable sticker sheet, and scanned code -> cow
    Route::get('holdings/{holding}/tags.pdf', [TagController::class, 'sheet']);
    Route::get('tags/{code}', [TagController::class, 'resolve'])->middleware('throttle:60,1');
});

// Farmers (full accounts)
Route::middleware(['auth:sanctum', 'role:farmer'])->group(function () {
    Route::post('mypost', [UserPostController::class, 'store']);   // free quick breed check

    // Farms (where animals are kept)
    Route::post('holdings', [HoldingController::class, 'store']);
    Route::put('holdings/{holding}', [HoldingController::class, 'update']);
    Route::delete('holdings/{holding}', [HoldingController::class, 'destroy']);

    // A farm's team
    Route::get('holdings/{holding}/members', [FarmMemberController::class, 'index']);
    Route::post('holdings/{holding}/workers', [FarmMemberController::class, 'storeWorker']);
    Route::post('holdings/{holding}/managers', [FarmMemberController::class, 'storeManager']);
    Route::put('holdings/{holding}/members/{member}/pin', [FarmMemberController::class, 'resetPin']);
    Route::delete('holdings/{holding}/members/{member}', [FarmMemberController::class, 'destroy']);

    // Herd
    Route::post('animals', [AnimalController::class, 'store']);
    Route::put('animals/{animal}', [AnimalController::class, 'update']);
    Route::delete('animals/{animal}', [AnimalController::class, 'destroy']);
    Route::post('animals/{animal}/photos', [AnimalController::class, 'uploadPhoto']);
    Route::delete('animals/{animal}/photos/{photo}', [AnimalController::class, 'deletePhoto']);

    // Certification requests for the farmer's own animals
    Route::post('animals/{animal}/certification-requests', [CertificationRequestController::class, 'store']);
    Route::put('certification-requests/{certificationRequest}/cancel', [CertificationRequestController::class, 'cancel']);
    Route::put('certification-requests/{certificationRequest}/resubmit', [CertificationRequestController::class, 'resubmit']);

    // Apply to become a certifying officer
    Route::get('officer-application', [OfficerApplicationController::class, 'show']);
    Route::post('officer-application', [OfficerApplicationController::class, 'store']);
});
