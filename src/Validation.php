<?php

namespace PHPFrame;

class Validation
{
    private $errors = [];
    
    /**
     * 验证数据
     */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            if (strpos($rule, 'required') !== false && ($value === null || $value === '')) {
                $this->errors[$field] = "{$field} 字段是必填的";
                continue;
            }
            
            if (strpos($rule, 'string') !== false && !is_string($value)) {
                $this->errors[$field] = "{$field} 字段必须是字符串";
            }
            
            if (strpos($rule, 'integer') !== false && !is_numeric($value)) {
                $this->errors[$field] = "{$field} 字段必须是整数";
            }
            
            if (strpos($rule, 'boolean') !== false && !is_bool($value)) {
                $this->errors[$field] = "{$field} 字段必须是布尔值";
            }
            
            if (strpos($rule, 'max:') !== false) {
                preg_match('/max:(\d+)/', $rule, $matches);
                $max = $matches[1] ?? null;
                if ($max && strlen($value) > $max) {
                    $this->errors[$field] = "{$field} 字段长度不能超过 {$max} 个字符";
                }
            }
            
            if (strpos($rule, 'in:') !== false) {
                preg_match('/in:([^,]+(?:,[^,]+)*)/', $rule, $matches);
                $allowed = explode(',', $matches[1] ?? '');
                if (!in_array($value, $allowed)) {
                    $this->errors[$field] = "{$field} 字段必须是以下值之一: " . implode(', ', $allowed);
                }
            }
            
            if (strpos($rule, 'email') !== false && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field] = "{$field} 字段必须是有效的邮箱地址";
            }
            
            if (strpos($rule, 'json') !== false && !$this->isValidJson($value)) {
                $this->errors[$field] = "{$field} 字段必须是有效的JSON格式";
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * 获取验证错误
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * 检查是否为有效的JSON
     */
    private function isValidJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}