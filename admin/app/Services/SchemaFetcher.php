<?php 

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Project;

class SchemaFetcher
{
    public function fetchAndStore(Project $project)
    {
        
        // Dynamic connection
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

        // Get tables
        $tables = DB::connection('client')->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = '".$project->db_name."'
        ");

        $schema = [];
        foreach ($tables as $tableRow) {
            $tableName = $tableRow->TABLE_NAME;
            $columns = DB::connection('client')->select("
                SELECT column_name, data_type
                FROM information_schema.columns
                WHERE table_name = '{$tableName}'
            ");
            $schema[$tableName] = [];
            foreach ($columns as $column) {
                $schema[$tableName][$column->COLUMN_NAME] = $column->DATA_TYPE;
            }
        }
        $project->db_schema = json_encode($schema);
        $project->save();
    }
}
