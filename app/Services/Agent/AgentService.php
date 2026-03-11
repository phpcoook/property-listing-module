<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Repositories\AgentRepository;

class AgentService
{
    public function __construct(
        protected AgentRepository $agentRepository
    ) {
    }

    public function createAgent(array $data): Agent
    {
        return $this->agentRepository->create($data);
    }
}
