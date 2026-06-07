<?php

namespace App\Http\Controllers;
use App\Services\OllamaService;
use App\Models\Client;
use App\Models\Project;
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
