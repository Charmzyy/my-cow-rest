<?php

namespace App\Http\Controllers;

use App\Models\CertificationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// The admin overview: every number is counted by MySQL, so the page costs the same with
// 50 requests or 50,000 (it used to download every row and count in the browser).
class AdminStatsController extends Controller
{
    public const OVERDUE_DAYS = 3;   // an open request older than this needs chasing

    public function index()
    {
        $requests = DB::table('certification_requests')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $certificates = DB::table('certificates')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $officers = DB::table('officer_profiles')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $roles = DB::table('users')->selectRaw('role, COUNT(*) AS n')->groupBy('role')->pluck('n', 'role');

        // Average hours from request to decision, over the last 90 days
        $turnaroundHours = DB::table('certification_requests')
            ->whereIn('status', ['approved', 'rejected'])
            ->where('decided_at', '>=', now()->subDays(90))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, decided_at)) AS h')
            ->value('h');

        return response()->json([
            'requests' => [
                'unassigned' => (int) ($requests['submitted'] ?? 0),
                'in_progress' => (int) collect(['assigned', 'in_review', 'needs_info'])->sum(fn ($s) => $requests[$s] ?? 0),
                'overdue' => DB::table('certification_requests')
                    ->whereIn('status', CertificationRequest::OPEN)
                    ->where('created_at', '<', now()->subDays(self::OVERDUE_DAYS))
                    ->count(),
                'overdue_days' => self::OVERDUE_DAYS,
                'oldest_waiting_at' => DB::table('certification_requests')->where('status', 'submitted')->min('created_at'),
                'turnaround_days' => $turnaroundHours !== null ? round($turnaroundHours / 24, 1) : null,
            ],
            'certificates' => [
                'valid' => (int) ($certificates['valid'] ?? 0),
                'revoked' => (int) ($certificates['revoked'] ?? 0),
                'this_month' => DB::table('certificates')->where('issued_at', '>=', now()->startOfMonth())->count(),
            ],
            'officers' => [
                'pending' => (int) ($officers['pending'] ?? 0),
                'approved' => (int) ($officers['approved'] ?? 0),
            ],
            'users' => [
                'total' => (int) $roles->sum(),
                'farmers' => (int) ($roles[User::ROLE_FARMER] ?? 0),
            ],
            'animals' => DB::table('animals')->whereNull('deleted_at')->count(),
            'quick_checks_pending' => DB::table('userposts')->whereNull('is_verified')->count(),

            // Valid certificates, top 8 each
            'by_breed' => DB::table('certificates')->where('status', 'valid')
                ->selectRaw('breed AS label, COUNT(*) AS n')->groupBy('breed')
                ->orderByDesc('n')->limit(8)->get()->map($this->row(...)),
            'by_county' => DB::table('certificates')->where('certificates.status', 'valid')
                ->join('animals', 'animals.id', '=', 'certificates.animal_id')
                ->join('counties', 'counties.id', '=', 'animals.county_id')
                ->selectRaw('counties.name AS label, COUNT(*) AS n')->groupBy('counties.name')
                ->orderByDesc('n')->limit(8)->get()->map($this->row(...)),
        ]);
    }

    private function row(object $r): array
    {
        return ['label' => $r->label, 'n' => (int) $r->n];
    }
}
