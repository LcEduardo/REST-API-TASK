<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Controller\ControllerBoss;
use App\Controller\ControllerTask;
use App\Controller\ControllerWorker;
use App\Model\Task;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControllerTaskTest extends TestCase
{
    private function controller(Task $task): ControllerTask
    {
        return new ControllerTask($task, $this->createStub(ControllerWorker::class), $this->createStub(ControllerBoss::class));
    }

    public function testGetTaskReturns400WhenIdIsNotInteger(): void
    {
        // GET /tasks/avc: o Postgres lança PDOException para "avc" em coluna integer, o que virava 500
        $task = $this->createStub(Task::class);
        $task->method('getById')->willThrowException(new PDOException('invalid input syntax for type integer'));

        $response = ($this->controller($task))->getTask('avc');

        $this->assertSame(400, $response['status']);
    }

    public function testGetTaskDoesNotQueryDatabaseWhenIdIsNotInteger(): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('getById');

        ($this->controller($task))->getTask('avc');
    }

    public function testGetTaskReturns200WithTheTask(): void
    {
        $row = ['id' => 1, 'title' => 'Tarefa'];

        $task = $this->createStub(Task::class);
        $task->method('getById')->willReturn($row);

        $response = ($this->controller($task))->getTask('1');

        $this->assertSame(200, $response['status']);
        $this->assertSame($row, $response['body']);
    }

    public function testGetAllTasksUsesPage1And20PerPageByDefault(): void
    {
        $rows = [
            ['id' => 1, 'title' => 'Tarefa 1'],
            ['id' => 2, 'title' => 'Tarefa 2'],
        ];

        $task = $this->createMock(Task::class);
        $task->expects($this->once())->method('getAll')->with(20, 0)->willReturn($rows);
        $task->method('count')->willReturn(2);

        $response = ($this->controller($task))->getAllTasks(null, null);

        $this->assertSame(200, $response['status']);
        $this->assertSame([
            'data'     => $rows,
            'page'     => 1,
            'per_page' => 20,
            'total'    => 2,
        ], $response['body']);
    }

    public function testGetAllTasksTranslatesPageIntoOffset(): void
    {
        // page=3 com 10 por página pula as 20 primeiras
        $task = $this->createMock(Task::class);
        $task->expects($this->once())->method('getAll')->with(10, 20)->willReturn([]);
        $task->method('count')->willReturn(25);

        $response = ($this->controller($task))->getAllTasks('3', '10');

        $this->assertSame(200, $response['status']);
        $this->assertSame(3, $response['body']['page']);
        $this->assertSame(10, $response['body']['per_page']);
        $this->assertSame(25, $response['body']['total']);
    }

    public function testGetAllTasksReturns200WithEmptyDataWhenPageIsPastTheEnd(): void
    {
        // página além do fim (ou tabela vazia) não é 404: só não tem itens
        $task = $this->createStub(Task::class);
        $task->method('getAll')->willReturn([]);
        $task->method('count')->willReturn(0);

        $response = ($this->controller($task))->getAllTasks('5', null);

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['data']);
        $this->assertSame(0, $response['body']['total']);
    }

    public static function invalidPages(): array
    {
        return [
            'texto'     => ['abc'],
            'vazio'     => [''],
            'zero'      => ['0'],
            'negativo'  => ['-1'],
            'decimal'   => ['1.5'],
            'array'     => [['1']],      // ?page[]=1
            'gigante'   => ['2147483648'], // offset estouraria
        ];
    }

    #[DataProvider('invalidPages')]
    public function testGetAllTasksReturns400WhenPageIsInvalid(mixed $page): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('getAll');

        $response = ($this->controller($task))->getAllTasks($page, null);

        $this->assertSame(400, $response['status']);
        $this->assertSame(['error' => 'The page must be a positive integer'], $response['body']);
    }

    public static function invalidPerPages(): array
    {
        return [
            'texto'        => ['abc'],
            'zero'         => ['0'],
            'negativo'     => ['-5'],
            'acima de 100' => ['101'],
            'array'        => [['10']],
        ];
    }

    #[DataProvider('invalidPerPages')]
    public function testGetAllTasksReturns400WhenPerPageIsInvalid(mixed $perPage): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('getAll');

        $response = ($this->controller($task))->getAllTasks(null, $perPage);

        $this->assertSame(400, $response['status']);
        $this->assertSame(['error' => 'The per_page must be an integer between 1 and 100'], $response['body']);
    }

    public function testGetAllTasksAccepts100PerPage(): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->once())->method('getAll')->with(100, 0)->willReturn([]);
        $task->method('count')->willReturn(0);

        $response = ($this->controller($task))->getAllTasks(null, '100');

        $this->assertSame(200, $response['status']);
    }

    public function testGetAllTasksReturns500WhenDatabaseFails(): void
    {
        $task = $this->createStub(Task::class);
        $task->method('getAll')->willThrowException(new PDOException('connection lost'));

        $response = ($this->controller($task))->getAllTasks(null, null);

        $this->assertSame(500, $response['status']);
        $this->assertSame(['error' => 'Database error'], $response['body']);
    }

    private function createController(Task $task, array $boss, array $worker): ControllerTask
    {
        $controllerBoss = $this->createStub(ControllerBoss::class);
        $controllerBoss->method('getBoss')->willReturn($boss);

        $controllerWorker = $this->createStub(ControllerWorker::class);
        $controllerWorker->method('getWorker')->willReturn($worker);

        return new ControllerTask($task, $controllerWorker, $controllerBoss);
    }

    public function testCreateTaskReturnsBossErrorAndDoesNotCreateWhenBossIsInvalid(): void
    {
        $bossError = ['status' => 404, 'body' => ['error' => 'Boss with id 999 not found']];

        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('create');

        $response = $this->createController($task, $bossError, ['status' => 200, 'body' => null])
            ->createTask(null, '999', null, 'Tarefa');

        $this->assertSame($bossError, $response);
    }

    public function testCreateTaskChecksBossBeforeWorker(): void
    {
        // os dois inválidos: o erro do boss é o que volta
        $bossError = ['status' => 400, 'body' => ['error' => "The boss_id isn't interger"]];
        $workerError = ['status' => 400, 'body' => ['error' => "The worker_id isn't interger"]];

        $response = $this->createController($this->createStub(Task::class), $bossError, $workerError)
            ->createTask('abc', 'abc', null, 'Tarefa');

        $this->assertSame($bossError, $response);
    }

    public function testCreateTaskReturnsWorkerErrorAndDoesNotCreateWhenWorkerIsInvalid(): void
    {
        $workerError = ['status' => 404, 'body' => ['error' => 'Worker with id 999 not found']];

        $task = $this->createMock(Task::class);
        $task->expects($this->never())->method('create');

        $response = $this->createController($task, ['status' => 200, 'body' => ['id' => 2]], $workerError)
            ->createTask('999', '2', null, 'Tarefa');

        $this->assertSame($workerError, $response);
    }

    public function testCreateTaskSavesTheIdsOfBossAndWorker(): void
    {
        $task = $this->createMock(Task::class);
        $task->expects($this->once())->method('create')->with([
            'title'       => 'Tarefa',
            'description' => 'Desc',
            'status'      => 'pending',
            'worker_id'   => 1,
            'boss_id'     => 2,
        ])->willReturn(10);
        $task->method('getById')->willReturn(['id' => 10]);

        $response = $this->createController($task, ['status' => 200, 'body' => ['id' => 2]], ['status' => 200, 'body' => ['id' => 1]])
            ->createTask('1', '2', 'Desc', 'Tarefa');

        $this->assertSame(201, $response['status']);
        $this->assertSame(['id' => 10], $response['body']);
    }

    public function testCreateTaskSavesNullWorkerWhenWorkerIsNotSent(): void
    {
        // worker é opcional: getWorker devolve body null
        $task = $this->createMock(Task::class);
        $task->expects($this->once())->method('create')
            ->with($this->callback(fn(array $data) => $data['worker_id'] === null && $data['boss_id'] === 2))
            ->willReturn(10);
        $task->method('getById')->willReturn(['id' => 10]);

        $response = $this->createController($task, ['status' => 200, 'body' => ['id' => 2]], ['status' => 200, 'body' => null])
            ->createTask(null, '2', null, 'Tarefa');

        $this->assertSame(201, $response['status']);
    }
}
