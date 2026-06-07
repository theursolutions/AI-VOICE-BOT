<?php
namespace Ursolutions\Tvaibwc\Controllers;

class SessionController
{
    public function getOrCreate($data)
    {
        return [
            "status" => "success",
            "message" => "Session Created",
            "data" => $data["customer_name"] ?? null
        ];
    }

}

