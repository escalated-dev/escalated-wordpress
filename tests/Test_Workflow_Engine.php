<?php

use Jeremykenedy\Escalated\Services\WorkflowEngine;
use PHPUnit\Framework\TestCase;

class Test_Workflow_Engine extends TestCase
{
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new WorkflowEngine();
    }

    public function test_evaluate_and_conditions()
    {
        $ticket = ['status' => 'open', 'priority' => 'medium'];
        $conditions = ['all' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
            ['field' => 'priority', 'operator' => 'equals', 'value' => 'medium'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function test_evaluate_or_conditions()
    {
        $ticket = ['status' => 'open'];
        $conditions = ['any' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'closed'],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function test_contains_operator()
    {
        $this->assertTrue(WorkflowEngine::applyOperator('contains', 'billing issue', 'billing'));
    }

    public function test_is_empty_operator()
    {
        $this->assertTrue(WorkflowEngine::applyOperator('is_empty', '', ''));
    }

    public function test_interpolate_variables()
    {
        $ticket = ['reference' => 'ESC-001', 'status' => 'open'];
        $result = WorkflowEngine::interpolateVariables('Ticket {{reference}} is {{status}}', $ticket);
        $this->assertEquals('Ticket ESC-001 is open', $result);
    }

    public function test_dry_run()
    {
        $ticket = ['status' => 'open', 'reference' => 'ESC-001'];
        $conditions = ['all' => [['field' => 'status', 'operator' => 'equals', 'value' => 'open']]];
        $actions = [['type' => 'add_note', 'value' => 'Note for {{reference}}']];
        $result = $this->engine->dryRun($conditions, $actions, $ticket);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('ESC-001', $result['actions'][0]['value']);
    }
}
