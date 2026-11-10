<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FarmMember;
use App\Models\Holding;
use App\Models\Organisation;
use App\Models\OrganisationMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Organisations (workspaces). Anyone with a farm account can start a co-op or company workspace;
// its owner and admins run it. Being in an organisation does NOT open its farms: farm access
// still comes from each farm's team (FarmMember::ABILITIES).
class OrganisationController extends Controller
{
    /** The workspaces I'm in, with my role in each. */
    public function index(Request $request)
    {
        return response()->json(['organisations' => self::listFor($request->user())]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([Organisation::COOP, Organisation::COMPANY])],
        ]);

        $org = new Organisation($data);
        $org->created_by = $request->user()->id;
        $org->save();
        $org->addMember($request->user(), OrganisationMember::OWNER);
        AuditLog::record('organisation.created', $org);

        return response()->json(['organisation' => $this->present($org, OrganisationMember::OWNER)], 201);
    }

    public function update(Request $request, Organisation $organisation)
    {
        $this->authorizeManager($request, $organisation);
        $organisation->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        return response()->json(['organisation' => $this->present($organisation, $organisation->roleOf($request->user()))]);
    }

    public function members(Request $request, Organisation $organisation)
    {
        $this->authorizeManager($request, $organisation);

        $members = $organisation->members()
            ->with('user:id,fullname,email,phone,login_phone,role')
            ->get()
            ->sortBy(fn ($m) => [array_search($m->role, OrganisationMember::ROLES), $m->user?->fullname])
            ->values();

        return response()->json(['members' => $members]);
    }

    /** Adds an existing account by email, as admin (owner only) or member. */
    public function addMember(Request $request, Organisation $organisation)
    {
        $this->authorizeManager($request, $organisation);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in([OrganisationMember::ADMIN, OrganisationMember::MEMBER])],
        ]);
        $this->authorizeRoleChange($request, $organisation, $data['role']);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            throw ValidationException::withMessages(['email' => 'No MyCow account uses this email. Ask them to create one first, then add them.']);
        }
        abort_if($organisation->roleOf($user) !== null, 422, "{$user->fullname} is already in this organisation.");

        $member = $organisation->addMember($user, $data['role'], $request->user());
        AuditLog::record('organisation.member_added', $organisation, ['user_id' => $user->id, 'role' => $data['role']]);

        return response()->json(['member' => $member->load('user:id,fullname,email,phone,login_phone,role')], 201);
    }

    /** Owner only: make someone an admin, or an admin a plain member again. */
    public function updateMember(Request $request, Organisation $organisation, OrganisationMember $member)
    {
        $this->assertIn($organisation, $member);
        abort_unless($organisation->roleOf($request->user()) === OrganisationMember::OWNER, 403, 'Only the owner can change roles.');
        abort_if($member->role === OrganisationMember::OWNER, 422, 'The owner’s role can’t be changed.');

        $member->update($request->validate(['role' => ['required', Rule::in([OrganisationMember::ADMIN, OrganisationMember::MEMBER])]]));

        return response()->json(['member' => $member->load('user:id,fullname,email,phone,login_phone,role')]);
    }

    public function removeMember(Request $request, Organisation $organisation, OrganisationMember $member)
    {
        $this->assertIn($organisation, $member);
        $this->authorizeManager($request, $organisation);
        abort_if($member->role === OrganisationMember::OWNER, 422, 'The owner can’t be removed.');
        $this->authorizeRoleChange($request, $organisation, $member->role);
        abort_if(
            FarmMember::where('user_id', $member->user_id)->whereIn('holding_id', $organisation->holdings()->withoutGlobalScopes()->select('id'))->exists(),
            422,
            'They are still on a farm’s team here. Remove them from those farms first.'
        );

        $member->delete();
        AuditLog::record('organisation.member_removed', $organisation, ['user_id' => $member->user_id]);

        return response()->json(['message' => 'Removed from the organisation.']);
    }

    /** The farm's owner moves it (with its animals and team) into a workspace they run. */
    public function moveFarm(Request $request, Holding $holding)
    {
        abort_unless($holding->allows($request->user(), 'farm.delete'), 403, 'Only the farm’s owner can move it.');
        $data = $request->validate(['organisation_id' => ['required', 'uuid']]);
        $target = Organisation::findOrFail($data['organisation_id']);
        abort_unless($target->isManagedBy($request->user()), 403, 'You can only move a farm into an organisation you own or run.');

        $from = $holding->organisation_id;
        $holding->moveTo($target);
        AuditLog::record('farm.moved', $holding, ['from' => $from, 'to' => $target->id]);

        return response()->json(['holding' => $holding->fresh()]);
    }

    /** For /orgs and /me: each workspace with the user's role and its counts. */
    public static function listFor(User $user)
    {
        $roles = OrganisationMember::where('user_id', $user->id)->pluck('role', 'organisation_id');

        return Organisation::whereIn('id', $roles->keys())
            ->withCount(['members', 'holdings' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByRaw("type = 'personal' DESC")
            ->orderBy('name')
            ->get()
            ->each(fn (Organisation $o) => $o->setAttribute('my_role', $roles[$o->id]));
    }

    private function present(Organisation $org, ?string $role): Organisation
    {
        return $org->loadCount(['members', 'holdings' => fn ($q) => $q->withoutGlobalScopes()])->setAttribute('my_role', $role);
    }

    private function authorizeManager(Request $request, Organisation $organisation): void
    {
        abort_unless($organisation->isManagedBy($request->user()), 403, 'Not Authorized');
    }

    /** Admins manage members; only the owner adds or removes admins. */
    private function authorizeRoleChange(Request $request, Organisation $organisation, string $targetRole): void
    {
        abort_if(
            $targetRole === OrganisationMember::ADMIN && $organisation->roleOf($request->user()) !== OrganisationMember::OWNER,
            403,
            'Only the owner can add or remove admins.'
        );
    }

    private function assertIn(Organisation $organisation, OrganisationMember $member): void
    {
        abort_unless($member->organisation_id === $organisation->id, 404);
    }
}
