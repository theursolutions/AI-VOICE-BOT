<?php

namespace App\Http\Controllers;
use App\Services\OllamaService;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\DataSource\SchemaAclFilter;
use App\Services\IntentClassifierService;
use DB;
use Illuminate\Http\Request;

class BotChatController extends Controller
{
    public function index($id)
    {
        return view('bot-chat' , compact(['id']));
    }

    public function handleQuery(Request $request, OllamaService $ollama)
    {
        $userQuery = $request->input('query');
        $project_id = $request->input('project_id');
        //$project_id = 1;
        $intentClassifier = new IntentClassifierService();
        $intent = $intentClassifier->classifyIntent($userQuery);

        if ($intent == 'data') {
            $schema = $this->getProjectSchema($project_id);
            if(!empty($schema)){
                // Apply the per-project DataSource ACL (allowed_tables +
                // allowed_columns) before the schema reaches the LLM —
                // same privacy guarantee as DatabaseResolver/AgentResolver.
                // Legacy controller still uses project.db_schema but we
                // honour the access rules saved on the most recent
                // matching DataSource so customer privacy isn't bypassed
                // through this route.
                $schemaArr = is_string($schema) ? json_decode($schema, true) : $schema;
                if (is_array($schemaArr)) {
                    $sourceCfg = $this->resolveAclConfig((int) $project_id);
                    if ($sourceCfg !== null) {
                        $schemaArr = app(SchemaAclFilter::class)->filter($schemaArr, $sourceCfg);
                        if (empty($schemaArr)) {
                            return response()->json([
                                'code' => 403,
                                'message' => 'No tables are allow-listed for AI access on this project.',
                            ]);
                        }
                    }
                    $schema = json_encode($schemaArr);
                }
            // Generate the SQL
                $sqlQuery = $ollama->generateSqlQuery($userQuery, $schema);
                $cleanedQuery = $this->cleanQuery($sqlQuery);
                /* if (!str_starts_with(strtolower(trim($cleanedQuery)), 'select')) {
                    return response()->json(['error' => 'Only SELECT queries allowed'], 400);
                } */

                $results = DB::connection('client')->select($cleanedQuery);
                return response()->json(['code' => 200,'data'=>$results,'intent_type'=> 'data', 'message' => "Bot send a response successfully"]);
            }
            else{
                return response()->json(['code' => 500, 'message' => "Schema could not found for this client"]);
            }
        }
        else if($intent === 'conversation'){
            $conv_response = $ollama->generateConversationalResponse($userQuery);
            return response()->json(['code' => 200,'intent_type'=> 'conversation', 'data'=>$conv_response, 'message' => "Bot send a response successfully"]);
        }
        else{
            return response()->json(['code' => 200,'intent_type'=> 'other', 'data'=>'Could not understand. Please try again', 'message' => "Could not understand. Please try again."]);
        }
    }

    /**
     * Locate the access-rules config for a project. Pulls from the
     * most recent active database/agent-type DataSource. Returns
     * null when no such source exists (legacy single-DB setups
     * pre-dating the DataSource model) — the caller then falls back
     * to passing through the full schema.
     */
    private function resolveAclConfig(int $projectId): ?array
    {
        $src = DataSource::where('project_id', $projectId)
            ->whereIn('type', [DataSource::TYPE_DATABASE, DataSource::TYPE_AGENT])
            ->where('is_active', 'Yes')
            ->orderByDesc('id')
            ->first();
        return $src ? (array) $src->config : null;
    }

    protected function getProjectSchema($projectId , $purgeConnection = true): string
    {
        $project = Project::where('id', $projectId)->first();
        if(!empty($project)){
            $db_password = "";
            if(!empty($project->db_password)){
                $db_password = $project->db_password;
            }
            config([
                'database.connections.client' => [
                    'driver' => $project->db_type,
                    'host' => $project->db_host,
                    'database' => $project->db_name,
                    'username' => $project->db_user,
                    'password' => $db_password,
                ]
            ]);

            if ($purgeConnection) {
                DB::purge('client');
            }

            if (!$project || !$project->db_schema) {
                return "No schema found for client ID {$projectId}.";
            }

            return $project->db_schema;
        }
        else{
             return "";
        }
    }

    public function cleanQuery(string $text): string
    {
        if(!empty($text)){
            $query = preg_replace('/```sql|```/', '', $text);
            return trim($query);
        }
        else{
            return $text;
        }
    }
}
