<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\SimpleSheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Team members of a client (agency): assign each a role + scope which
 * projects they can see. Owner-only. A null project scope = all projects;
 * otherwise specific project rows. Roles come from RoleWebController.
 */
class MemberWebController extends Controller
{
    public function index(Request $request, Client $client): View
    {
        $this->guardOwner($request, $client);

        $rows = DB::table('project_users')->where('client_id', $client->id)->get();

        $userIds = $rows->pluck('user_id')->unique()->filter()->values();
        $users   = User::whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');
        $roles   = Role::where('client_id', $client->id)->orderByDesc('is_owner')->orderBy('name')->get();
        $projects = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name']);

        // Build a per-member summary: role + project scope.
        $members = [];
        foreach ($rows->groupBy('user_id') as $uid => $userRows) {
            $u = $users->get($uid);
            if (!$u) continue;
            $roleId    = optional($userRows->firstWhere('role_id', '!=', null))->role_id;
            $allAccess = $userRows->contains(fn ($r) => $r->project_id === null);
            $projectIds = $allAccess ? [] : $userRows->pluck('project_id')->filter()->map(fn ($v) => (int) $v)->values()->all();
            $members[] = [
                'user'        => $u,
                'role_id'     => $roleId,
                'role'        => $roleId ? $roles->firstWhere('id', $roleId) : null,
                'all_access'  => $allAccess,
                'project_ids' => $projectIds,
            ];
        }

        return view('members.index', compact('client', 'members', 'roles', 'projects'));
    }

    public function update(Request $request, Client $client, int $userId): RedirectResponse
    {
        $this->guardOwner($request, $client);

        $data = $request->validate([
            'role_id'      => 'required|integer',
            'scope'        => 'required|in:all,projects',
            'project_ids'  => 'array',
            'project_ids.*'=> 'integer',
        ]);

        $target = User::findOrFail($userId);
        $role   = Role::where('client_id', $client->id)->findOrFail((int) $data['role_id']);

        // Don't let the owner be reassigned/demoted from this screen.
        if ($target->isOwnerOf($client->id)) {
            return back()->withErrors(['member' => 'The owner cannot be edited here.']);
        }

        $isMember = DB::table('project_users')->where('client_id', $client->id)->where('user_id', $userId)->exists();
        if (!$isMember) {
            return back()->withErrors(['member' => 'That user is not a member of this workspace.']);
        }

        // Resolve the project scope.
        $projectIds = null; // null = all
        if ($data['scope'] === 'projects') {
            $valid = Project::where('client_id', $client->id)->pluck('id')->all();
            $projectIds = array_values(array_intersect(array_map('intval', $data['project_ids'] ?? []), $valid));
            if (empty($projectIds)) {
                return back()->withErrors(['member' => 'Pick at least one project, or choose "All projects".']);
            }
        }

        $this->rebuildMembership($userId, (int) $client->id, (int) $role->id, $projectIds, (int) ($request->user()->id ?? 0));

        return back()->with('success', "Updated {$target->name}'s access.");
    }

    /**
     * Add a single member directly (no email invite). Creates the account
     * if the email is new, or attaches an existing user, then applies the
     * chosen role + project scope. Owner-only.
     */
    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->guardOwner($request, $client);

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|max:255',
            'password'      => 'nullable|string|min:8|max:255',
            'role_id'       => 'required|integer',
            'scope'         => 'required|in:all,projects',
            'project_ids'   => 'array',
            'project_ids.*' => 'integer',
        ]);

        $role = Role::where('client_id', $client->id)->where('is_owner', false)->find((int) $data['role_id']);
        if (!$role) {
            return back()->withErrors(['member' => 'Pick a valid role.'])->withInput();
        }

        $projectIds = $this->resolveScope($client, $data['scope'], $data['project_ids'] ?? []);
        if ($projectIds === false) {
            return back()->withErrors(['member' => 'Pick at least one project, or choose "All projects".'])->withInput();
        }

        $res = $this->upsertMember(
            $client,
            $data['email'],
            $data['name'],
            $data['password'] ?? null,
            (int) $role->id,
            $projectIds,
            (int) ($request->user()->id ?? 0),
        );

        if (!empty($res['error'])) {
            return back()->withErrors(['member' => $res['error']])->withInput();
        }

        $msg = $res['created']
            ? "Created {$res['email']} and added them to the workspace."
            : "Added {$res['email']} to the workspace.";
        if (!empty($res['temp_password'])) {
            $msg .= " Temporary password: {$res['temp_password']} — share it securely; they should change it after first login.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Bulk-create members from an uploaded CSV / XLSX. Each row maps to a
     * member (create-or-attach) with a role + optional project scope.
     * Columns (header row, any order): name, email, role, password, projects.
     * Owner-only.
     */
    public function import(Request $request, Client $client): RedirectResponse
    {
        $this->guardOwner($request, $client);

        // Validate the upload itself, then check the extension by hand — the
        // `mimes` rule is flaky for CSV/XLSX because MIME guessing differs
        // across OSes (CSV → text/plain, XLSX → application/zip, etc.).
        $request->validate([
            'file' => 'required|file|max:5120',
        ]);
        $upload = $request->file('file');
        $ext    = strtolower($upload->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'txt', 'tsv', 'xlsx'], true)) {
            return back()->withErrors(['import' => 'Unsupported file type. Upload a .csv or .xlsx file.']);
        }

        $roles = Role::where('client_id', $client->id)->where('is_owner', false)->get();
        if ($roles->isEmpty()) {
            return back()->withErrors(['import' => 'Create at least one role before importing members.']);
        }
        $rolesByName  = $roles->keyBy(fn ($r) => mb_strtolower(trim($r->name)));
        $fallbackRole = $roles->first();

        $projectsByName = Project::where('client_id', $client->id)
            ->get(['id', 'name'])
            ->keyBy(fn ($p) => mb_strtolower(trim($p->name)));

        try {
            $rows = SimpleSheetReader::read($upload->getRealPath(), $ext);
        } catch (\Throwable $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        if (count($rows) < 2) {
            return back()->withErrors(['import' => 'The file has a header but no data rows. Use the template as a guide.']);
        }

        // Map header → column index by name (case-insensitive).
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows));
        $col = function (array $row, array $names) use ($header) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false && isset($row[$i])) {
                    return trim((string) $row[$i]);
                }
            }
            return '';
        };

        $assignedBy = (int) ($request->user()->id ?? 0);
        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($rows as $n => $row) {
            $line  = $n + 2; // human-friendly row number (data starts after header)
            $email = mb_strtolower($col($row, ['email', 'e-mail', 'mail']));
            if ($email === '') {
                continue; // ignore blank lines outright
            }

            $name     = $col($row, ['name', 'full name', 'fullname']);
            $password = $col($row, ['password', 'pass']);
            $roleName = mb_strtolower($col($row, ['role', 'role name']));
            $role     = $rolesByName->get($roleName) ?? $fallbackRole;

            // Scope: a "projects" column with comma/semicolon-separated names,
            // or "all"/blank for every project.
            $scopeRaw   = $col($row, ['projects', 'project', 'project access', 'scope']);
            $projectIds = null; // null = all projects
            if ($scopeRaw !== '' && mb_strtolower($scopeRaw) !== 'all') {
                $ids = [];
                foreach (preg_split('/[,;|]+/', $scopeRaw) as $pname) {
                    $p = $projectsByName->get(mb_strtolower(trim($pname)));
                    if ($p) {
                        $ids[] = (int) $p->id;
                    }
                }
                $projectIds = !empty($ids) ? array_values(array_unique($ids)) : null;
            }

            $res = $this->upsertMember($client, $email, $name, $password ?: null, (int) $role->id, $projectIds, $assignedBy);
            if (!empty($res['error'])) {
                $skipped[] = "Row {$line}: {$res['error']}";
                continue;
            }
            if ($res['created']) {
                $created[] = ['email' => $email, 'role' => $role->name, 'temp_password' => $res['temp_password']];
            } else {
                $updated[] = ['email' => $email, 'role' => $role->name];
            }
        }

        return back()->with('import_result', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    /** Download a ready-to-fill CSV template for the bulk importer. */
    public function template(Request $request, Client $client): Response
    {
        $this->guardOwner($request, $client);

        $rows = [
            ['name', 'email', 'role', 'password', 'projects'],
            ['John Doe', 'john@example.com', 'Agent', '', 'All'],
            ['Jane Smith', 'jane@example.com', 'Manager', 'ChangeMe123', 'Sales, Support'],
        ];

        $csv = '';
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(function ($v) {
                $v = (string) $v;
                return (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n"))
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : $v;
            }, $r)) . "\r\n";
        }
        // UTF-8 BOM so Excel opens it without mangling characters.
        $csv = "\xEF\xBB\xBF" . $csv;

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="members-template.csv"',
        ]);
    }

    /**
     * Translate a scope selection into a project-id list.
     * Returns null for all-projects, an int[] for specific projects, or
     * false when "specific" was chosen but no valid project was picked.
     *
     * @param  array<int|string>  $projectIds
     * @return array<int>|null|false
     */
    private function resolveScope(Client $client, string $scope, array $projectIds): array|null|false
    {
        if ($scope === 'all') {
            return null;
        }
        $valid = Project::where('client_id', $client->id)->pluck('id')->all();
        $ids   = array_values(array_intersect(array_map('intval', $projectIds), $valid));
        return empty($ids) ? false : $ids;
    }

    /**
     * Create-or-find a user by email and (re)apply their membership for the
     * given client. New emails get an account (password as supplied, else a
     * generated temp password returned in the result). The agency owner is
     * never modified through this path.
     *
     * @param  array<int>|null  $projectIds  null = all projects
     * @return array{email:string, created?:bool, temp_password?:?string, error?:string}
     */
    private function upsertMember(Client $client, string $email, ?string $name, ?string $password, int $roleId, ?array $projectIds, int $assignedBy): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'error' => 'Invalid email address.'];
        }

        $temp    = null;
        $created = false;
        $existing = User::where('email', $email)->first();

        if ($existing) {
            // The agency owner is managed elsewhere — never overwrite here.
            if ($existing->isOwnerOf($client->id)) {
                return ['email' => $email, 'error' => 'This is the workspace owner — manage them separately.'];
            }
            $user = $existing;
        } else {
            if ($password === null || trim($password) === '') {
                $temp = $this->randomPassword();
            }
            $user = User::create([
                'name'             => $name ?: (string) Str::of($email)->before('@')->headline(),
                'email'            => $email,
                // The User model's 'hashed' cast hashes this on save.
                'password'         => $password ?: $temp,
                'active_client_id' => $client->id,
            ]);
            $created = true;
        }

        $this->rebuildMembership((int) $user->id, (int) $client->id, $roleId, $projectIds, $assignedBy);

        return ['email' => $email, 'created' => $created, 'temp_password' => $temp];
    }

    /** A readable, reasonably strong temporary password. */
    private function randomPassword(int $length = 10): string
    {
        return Str::random($length);
    }

    /** Replace a member's rows with the desired role + project scope. */
    private function rebuildMembership(int $userId, int $clientId, int $roleId, ?array $projectIds, int $assignedBy): void
    {
        DB::transaction(function () use ($userId, $clientId, $roleId, $projectIds, $assignedBy) {
            DB::table('project_users')->where('client_id', $clientId)->where('user_id', $userId)->delete();

            $base = [
                'user_id'     => $userId,
                'client_id'   => $clientId,
                'role_id'     => $roleId,
                'assigned_by' => $assignedBy,
                'assigned_at' => time(),
                'created_at'  => time(),
                'updated_at'  => time(),
            ];

            if ($projectIds === null) {
                DB::table('project_users')->insert($base + ['project_id' => null]);
            } else {
                foreach ($projectIds as $pid) {
                    DB::table('project_users')->insert($base + ['project_id' => $pid]);
                }
            }
        });
    }

    private function guardOwner(Request $request, Client $client): void
    {
        $user = $request->user();
        if (!$user || !$user->isOwnerOf($client->id)) {
            abort(403, 'Only the agency owner can manage members.');
        }
    }
}
