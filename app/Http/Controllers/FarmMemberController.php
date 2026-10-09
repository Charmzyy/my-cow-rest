<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\User;
use App\Support\Phone;
use App\Support\Pin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// A farm's team. Owners manage everyone; managers can add and remove workers only.
//   workers:  added by phone + PIN (no email needed); they sign in at POST /login/pin
//   managers: an existing MyCow account, added by its email
class FarmMemberController extends Controller
{
    public function index(Request $request, Holding $holding)
    {
        $this->authorizeTeam($request, $holding, FarmMember::WORKER);

        $members = $holding->members()
            ->with('user:id,fullname,email,phone,login_phone')
            ->orderByRaw("FIELD(role, 'owner', 'manager', 'vet', 'worker')")
            ->orderBy('created_at')
            ->get();

        return response()->json(['members' => $members]);
    }

    public function storeWorker(Request $request, Holding $holding)
    {
        $this->authorizeTeam($request, $holding, FarmMember::WORKER);

        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20'],
            'pin' => Pin::rules(),
        ]);
        $phone = Phone::normalize($data['phone']);
        if (!$phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a Kenyan mobile number, e.g. 0712 345 678.']);
        }

        [$member, $existing] = DB::transaction(function () use ($request, $holding, $data, $phone) {
            // A worker already on another farm keeps one account (and their PIN) for all farms
            $worker = User::where('login_phone', $phone)->lockForUpdate()->first();
            $existing = (bool) $worker;
            if (!$worker) {
                $worker = new User(['fullname' => $data['fullname'], 'phone' => $phone]);
                $worker->forceFill([
                    'role' => User::ROLE_WORKER,
                    'login_phone' => $phone,
                    'password' => Hash::make($data['pin']),
                ])->save();
            }

            return [$this->addMember($request, $holding, $worker, FarmMember::WORKER), $existing];
        });

        return response()->json([
            'member' => $member->load('user:id,fullname,email,phone,login_phone'),
            // their PIN from the other farm still applies; the one typed here was not used
            'existing_account' => $existing,
        ], 201);
    }

    public function storeManager(Request $request, Holding $holding)
    {
        $this->authorizeTeam($request, $holding, FarmMember::MANAGER);

        $data = $request->validate(['email' => ['required', 'email']], [], ['email' => 'email address']);
        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => 'No MyCow account uses this email. Ask them to create one first, then add them.']);
        }
        if (!$user->hasRole(User::ROLE_FARMER)) {
            throw ValidationException::withMessages(['email' => 'Only a farmer account can manage a farm.']);
        }

        $member = $this->addMember($request, $holding, $user, FarmMember::MANAGER);

        return response()->json(['member' => $member->load('user:id,fullname,email,phone,login_phone')], 201);
    }

    /** A worker forgot their PIN: the farm sets a new one and their phones are signed out. */
    public function resetPin(Request $request, Holding $holding, FarmMember $member)
    {
        $this->assertOnFarm($holding, $member);
        $this->authorizeTeam($request, $holding, $member->role);
        abort_unless($member->user->login_phone, 422, 'Only PIN accounts have a PIN. Managers reset their password by email.');

        $data = $request->validate(['pin' => Pin::rules()]);
        $member->user->forceFill(['password' => Hash::make($data['pin'])])->save();
        $member->user->tokens()->delete();
        AuditLog::record('member.pin_reset', $member);

        return response()->json(['message' => 'PIN changed. They need to sign in again with the new PIN.']);
    }

    public function destroy(Request $request, Holding $holding, FarmMember $member)
    {
        $this->assertOnFarm($holding, $member);
        abort_if($member->role === FarmMember::OWNER, 422, 'The owner can’t be removed from their own farm.');
        $this->authorizeTeam($request, $holding, $member->role);

        $member->delete();
        AuditLog::record('member.removed', $member, ['user_id' => $member->user_id, 'role' => $member->role]);

        // A PIN account with no farm left has nothing to sign in to: end its sessions now
        $user = $member->user;
        if ($user->hasRole(User::ROLE_WORKER) && !$user->memberships()->exists()) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Removed from the farm.']);
    }

    private function addMember(Request $request, Holding $holding, User $user, string $role): FarmMember
    {
        abort_if($holding->roleOf($user) !== null, 422, "{$user->fullname} is already on this farm’s team.");

        $member = new FarmMember(['role' => $role]);
        $member->forceFill(['holding_id' => $holding->id, 'user_id' => $user->id, 'added_by' => $request->user()->id])->save();
        AuditLog::record('member.added', $member, ['user_id' => $user->id, 'role' => $role]);

        return $member;
    }

    /** Owners manage anyone; managers manage workers (and vets) only. */
    private function authorizeTeam(Request $request, Holding $holding, string $targetRole): void
    {
        $mine = $holding->roleOf($request->user());
        abort_unless(FarmMember::allows($mine, 'team.manage'), 403, 'Not Authorized');
        abort_if($mine === FarmMember::MANAGER && $targetRole === FarmMember::MANAGER, 403, 'Only the owner can add or remove managers.');
    }

    private function assertOnFarm(Holding $holding, FarmMember $member): void
    {
        abort_unless($member->holding_id === $holding->id, 404);
    }
}
