<?php

namespace App\Repositories;

use App\Models\Agent;

class AgentRepository
{
    public function create(array $data): Agent
    {
        return Agent::create($data);
    }
}
