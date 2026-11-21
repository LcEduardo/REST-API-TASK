<?php

namespace App\Controller;

use App\Model\Worker;
use Exception;
use PDOException;

class ControllerWorker extends Controller
{
    private Worker $worker;

    public function __construct(Worker  $worker) {
        $this->worker = $worker;
    }

    public function getWorker($id) {
        // worker é opcional: sem id não tem worker, então o body volta null
        if ($id === null || $id === '') {
            return [
                "status" => 200,
                "body" => null,
            ];
        }

        try {

            $id = filter_var($id, FILTER_VALIDATE_INT);

            if ($id === false) {
                 throw new Exception("The worker_id isn't interger", 400);
            }

            $worker = $this->worker->getById($id);

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
            "body" => $worker,
        ];
    }

    public function createWorker($name, $email) {
        try {

            $data = $this->validateFields($name, $email);

            $workerId = $this->worker->create($data);

            $worker = $this->worker->getById($workerId);

        }catch(PDOException $e) {
            return $this->databaseError($e);
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 201,
            "body" => $worker,
        ];
    }

    // PUT substitui o registro inteiro: name e email são obrigatórios, com as mesmas regras do create
    public function updateWorker($id, $name, $email) {
        try {

            $id = filter_var($id, FILTER_VALIDATE_INT);

            if ($id === false) {
                 throw new Exception("The worker_id isn't interger", 400);
            }

            $data = $this->validateFields($name, $email);

            $this->worker->update($id, $data);

            $worker = $this->worker->getById($id);

        }catch(PDOException $e) {
            return $this->databaseError($e);
        }catch(Exception $e) {
            return [
                "status" => $e->getCode(),
                "body" => ['error' => $e->getMessage()],
            ];
        }

        return [
            "status" => 200,
            "body" => $worker,
        ];
    }

}
