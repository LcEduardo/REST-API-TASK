<?php

namespace App\Controller;

use App\Model\Task;
use Exception;
use PDOException;

class ControllerTask 
{
    private Task $task;

    public function __construct(Task $task) {
        $this->task = $task;
    }

    public function getTask($id) {
        try {
            // /tasks/avc chegaria no Postgres como erro de tipo (PDOException -> 500), então valida antes
            $id = filter_var($id, FILTER_VALIDATE_INT);

            if ($id === false) {
                throw new Exception("The task id isn't integer", 400);
            }

            $task = $this->task->getById($id);

        }catch(PDOException $e) {
            return [
                "status" => 500,
                "body" => ['error' => 'Database error'],
            ];
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 200,
            "body" => $task,
        ];
    }

    // $page e $perPage vêm da query string (?page=2&per_page=10): string, array ou null se não enviados
    public function getAllTasks($page, $perPage) {
        try {
            // max_range no page: (page - 1) * per_page não pode estourar o OFFSET
            $page = $page === null ? 1 : filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

            if ($page === false) {
                throw new Exception("The page must be a positive integer", 400);
            }

            $perPage = $perPage === null ? 20 : filter_var($perPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

            if ($perPage === false) {
                throw new Exception("The per_page must be an integer between 1 and 100", 400);
            }

            $tasks = $this->task->getAll($perPage, ($page - 1) * $perPage);
            $total = $this->task->count();

        }catch(PDOException $e) {
            return [
                "status" => 500,
                "body" => ['error' => 'Database error'],
            ];
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        // página sem tasks volta data [] com 200, não 404
        return [
            "status" => 200,
            "body" => [
                'data'     => $tasks,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
            ],
        ];
    }

    public function createTask($worker, $boss, $description, $title) {
        try {

            // title[]=a chega como array; trim() em array lança TypeError (não é Exception)
            if (($title !== null && !is_string($title)) || ($description !== null && !is_string($description))) {
                throw new Exception("The title and description must be strings", 400);
            }

            // title=" " vira "" depois do trim, então conta como não enviado
            $title = trim($title ?? '');

            if ($title === '') {
                throw new Exception("The title is required", 400);
            }

            $taskId = $this->task->create([
                'title'       => $title,
                'description' => $description,
                'status'      => 'pending',   //default
                'worker_id'   => $worker['id'] ?? null,
                'boss_id'     => $boss['id'],
            ]);

            $task = $this->task->getById($taskId);

        }catch(PDOException $e) {
            return [
                "status" => 500,
                "body" => ['error' => 'Database error'],
            ];
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 201,
            "body" => $task,
        ];
    }
}