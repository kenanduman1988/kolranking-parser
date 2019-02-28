<?php

namespace kolranking;

/**
 * Class Events
 */
class Events
{
    /**
     * @param string $type
     * @param string $message
     */
    public static function log(string $type, string $message): void
    {
        $file = fopen(ROOT . "/log/{$type}.log", 'a');
        $message = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        echo $message;
        fwrite($file, $message);
        fclose($file);

    }
}