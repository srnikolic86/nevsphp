<?php

namespace Nevs;

class Request
{
    public array $parameters = [];
    public array $data = [];
    public array $files = [];

    public function __construct(public string $url, public string $method)
    {
        if ($method == 'GET') {
            $this->data = $_GET;
        } else {
            $this->data = $_POST;
            $raw_content = file_get_contents('php://input');
            if ($raw_content != '') {
                $json_data = json_decode($raw_content, true);
                if ($json_data != null) {
                    $this->data = $json_data;
                } else {
                    Log::Write('Request', 'malformed JSON data');
                }
            }
            $this->files = $_FILES;
        }
    }

    public function Serialize(): string
    {
        return json_encode([
            'url' => $this->url,
            'method' => $this->method,
            'parameters' => $this->parameters
        ]);
    }

    public function Validate(array $validations): bool|array
    {
        $response = [];

        foreach ($validations as $parameter => $rule) {
            $valid = true;
            if (isset($this->data[$parameter])) {
                $value = $this->data[$parameter];
                switch ($rule) {
                    case 'int':
                        if (!is_int($value)) {
                            $valid = false;
                        }
                        break;
                    case 'float':
                        if (!is_float($value) && !is_int($value)) {
                            $valid = false;
                        }
                        break;
                    case 'bool':
                        if (!is_bool($value)) {
                            $valid = false;
                        }
                        break;
                    case 'string':
                        if (!is_string($value)) {
                            $valid = false;
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $valid = false;
                        }
                        break;
                    case 'email':
                        if (!is_string($value)) {
                            $valid = false;
                        } else {
                            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                $valid = false;
                            }
                        }
                        break;
                }
            } else {
                $valid = false;
            }
            if (!$valid) {
                $response[$parameter] = $rule;
            }
        }

        if ($response != []) {
            Log::Write('Request', 'invalid payload: ' . json_encode($response));
        }

        return ($response == []) ? true : $response;
    }
}