<?php

namespace App\Traits;

trait EntityBehavior
{
    /**
     * Magic getter that prioritizes explicit getter methods
     */
    public function __get($key)
    {
        $getter = 'get'.$this->camelCase($key);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }

        return parent::__get($key);
    }

    /**
     * Magic setter that prioritizes explicit setter methods
     */
    public function __set($key, $value)
    {
        $setter = 'set'.$this->camelCase($key);
        if (method_exists($this, $setter)) {
            $this->$setter($value);

            return;
        }

        parent::__set($key, $value);
    }

    /**
     * Helper to convert snake_case to CamelCase
     */
    private function camelCase(string $str): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
    }
}
