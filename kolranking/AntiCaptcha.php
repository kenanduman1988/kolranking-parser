<?php
/**
 * Created by PhpStorm.
 * User: kenanduman
 * Date: 10/23/18
 * Time: 1:23 PM
 */

namespace kolranking;


use Anticaptcha\Client;

class AntiCaptcha
{
    public function getClient()
    {
        return new Client(
            'a4f30fa2bbbc822e1a0fc0c85b37ad4f',
            [
                'languagePool' => 'en'
            ]);
    }

    /**
     * @param $image
     * @return null|string
     * @throws \Exception
     */
    public function getText($image)
    {
        $text = null;
        try {
            $client = $this->getClient();
            $taskId = $client->createTaskByImage($image);
            $result = $client->getTaskResult($taskId);
            $result->await();
            $text = $result->getSolution()->getText();
        } catch (\Exception $e) {
            return false;
        }

        return $text;
    }
}