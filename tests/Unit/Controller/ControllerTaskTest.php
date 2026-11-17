<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\ControllerTask;
use App\Model\Task;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ControllerTaskTest extends TestCase
{
    public function testGetTaskReturns400WhenIdIsNotInteger(): void
    {
        // GET /tasks/avc: o Postgres lança PDOException para "avc" em coluna integer, o que virava 500
        $task = $this->createStub(Task::class);
        $task->method('getById')->willThrowException(new PDOException('invalid input syntax for type integer'));

        $response = (new ControllerTask($task))->getTask('avc');

        $this->assertSame(400, $response['status']);
    }

    public function testGetTaskDoesNotQueryDatabaseWhenIdIsNotInteger(): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('getById');

        (new ControllerTask($task))->getTask('avc');
    }

    public function testGetTaskReturns200WithTheTask(): void
    {
        $row = ['id' => 1, 'title' => 'Tarefa'];

        $task = $this->createStub(Task::class);
        $task->method('getById')->willReturn($row);

        $response = (new ControllerTask($task))->getTask('1');

        $this->assertSame(200, $response['status']);
        $this->assertSame($row, $response['body']);
    }

    public function testGetAllTasksReturns200WithAllTasks(): void
    {
        $rows = [
            ['id' => 1, 'title' => 'Tarefa 1'],
            ['id' => 2, 'title' => 'Tarefa 2'],
        ];

        $task = $this->createStub(Task::class);
        $task->method('getAll')->willReturn($rows);

        $response = (new ControllerTask($task))->getAllTasks();

        $this->assertSame(200, $response['status']);
        $this->assertSame($rows, $response['body']);
    }

    public function testGetAllTasksReturns200WithEmptyArrayWhenThereAreNoTasks(): void
    {
        // lista vazia não é 404: a coleção existe, só não tem itens
        $task = $this->createStub(Task::class);
        $task->method('getAll')->willReturn([]);

        $response = (new ControllerTask($task))->getAllTasks();

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']);
    }

    public function testGetAllTasksReturns500WhenDatabaseFails(): void
    {
        $task = $this->createStub(Task::class);
        $task->method('getAll')->willThrowException(new PDOException('connection lost'));

        $response = (new ControllerTask($task))->getAllTasks();

        $this->assertSame(500, $response['status']);
        $this->assertSame(['error' => 'Database error'], $response['body']);
    }
}
