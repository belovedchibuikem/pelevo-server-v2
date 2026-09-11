<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DB::table('studios')->join('studio_members', 'studio_members.studio_id', '=', 'studios.id')->where('studio_members.user_id', $request->user()->id)->select('studios.*', 'studio_members.role')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        $creator = CreatorProfile::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless(DB::table('verified_show_claims')->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')->where('show_claims.creator_profile_id', $creator->id)->exists(), 403);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $creator, $request, $data) {
            DB::table('studios')->insert(['id' => $id, 'creator_profile_id' => $creator->id, 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('studio_members')->insert(['studio_id' => $id, 'user_id' => $request->user()->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        });

        return ApiResponse::success(DB::table('studios')->find($id), status: 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $studio = $this->owned($id, $request);
        $studio->members = DB::table('studio_members')->where('studio_id', $id)->get();

        return ApiResponse::success($studio);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->owned($id, $request, true);
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);
        DB::table('studios')->where('id', $id)->update([...$data, 'updated_at' => now()]);

        return ApiResponse::success(DB::table('studios')->find($id));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $this->owned($id, $request, true);
        DB::table('studios')->where('id', $id)->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    public function addMember(string $id, Request $request): JsonResponse
    {
        $this->owned($id, $request, true);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'role' => ['required', 'in:editor,analyst']]);
        DB::table('studio_members')->updateOrInsert(['studio_id' => $id, 'user_id' => $data['user_id']], ['role' => $data['role'], 'created_at' => now(), 'updated_at' => now()]);

        return ApiResponse::success(['added' => true]);
    }

    private function owned(string $id, Request $request, bool $owner = false): object
    {
        $query = DB::table('studios')->join('studio_members', 'studio_members.studio_id', '=', 'studios.id')->where('studios.id', $id)->where('studio_members.user_id', $request->user()->id);
        if ($owner) {
            $query->where('studio_members.role', 'owner');
        }

return $query->select('studios.*','studio_members.role')->firstOrFail();
    }
}
