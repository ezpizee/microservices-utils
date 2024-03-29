<?php

namespace Ezpizee\Utils;

use Ezpizee\Utils\Encryption\Encryptor;
use RuntimeException;

final class RequestBodyValidator
{
    private function __construct()
    {
    }

    public static function validate(ListModel $field, $v)
    : void
    {
        if ($field->is('type', 'string')) {
            if (is_array($v)||is_object($v)) {$v=json_encode($v);}
            self::validateString($field, $v);
        }
        else if ($field->is('type', 'json')) {
            if (is_array($v)||is_object($v)) {$v=json_encode($v);}
            self::validateJSON($field, $v);
        }
        else if ($field->is('type', 'number')) {
            if (is_array($v)||is_object($v)) {$v=json_encode($v);}
            self::validateNumber($field, $v);
        }
        else if ($field->is('type', 'boolean')) {
            self::validateBoolean($field, $v);
        }
        else if ($field->is('type', 'email')) {
            if (is_array($v)||is_object($v)) {$v=json_encode($v);}
            self::validateEmail($field, $v);
        }
        else if ($field->is('type', 'url')) {
            if (is_array($v)||is_object($v)) {$v=json_encode($v);}
            self::validateURL($field, $v);
        }
        else if ($field->is('type', 'array')) {
            self::validateArray($field, $v);
        }
        else if ($field->is('type', 'file')) {
            self::validateFile($field);
        }
        else if ($field->is('type', 'mixed')) {
            self::validateMixed($field, $v);
        }
        else if ($field->is('type', 'client_credential')) {
            self::validateClientCredentials($field, $v);
        }
        else if ($field->is('type', 'password')) {
            if (!Password::isValid($v)) {
                self::throwError($field);
            }
        }
        else if ($field->is('type', 'sku')) {
            if (!SKU::isValid($v)) {
                self::throwError($field);
            }
        }
        else if ($field->is('type', 'uuid')) {
            if (!UUID::isValid($v)) {
                self::throwError($field);
            }
        }
        else if ($field->is('type', 'aes_encrypted')) {
            self::validateAESEncryptedData($field, $v);
        }
    }

    public static function validateString(ListModel $field, string $v)
    : void
    {
        if ($field->get('size', 0)) {
            if (!strlen($v)) {
                self::throwError($field);
            }
            else {
                // if request length is greater or equal the required length
                if (self::validateSize($field->get('size', 0), strlen($v))) {
                    $defaultValue = $field->get('defaultValue', []);
                    // if check default value
                    if (sizeof($defaultValue) > 0) {
                        // if request value is in one of the default values
                        if (!in_array($v, $defaultValue)) {
                            self::throwError($field);
                        }
                    }
                }
                else {
                    self::throwError($field);
                }
            }
        }
    }

    public static function throwError(ListModel $field)
    : void
    {
        if (defined('DEBUG') && DEBUG) {
            CustomResponse::setDebugInfo($field->getAsArray());
        }
        Logger::debug($field);
        throw new RuntimeException(ResponseCodes::MESSAGE_ERROR_INVALID_FIELD, ResponseCodes::CODE_ERROR_INVALID_FIELD);
    }

    private static function validateSize($size, $num)
    : bool
    {
        $minMax = $size ? explode('-', str_replace(['[', ']'], '', $size)) : [];
        if (sizeof($minMax) === 2) {
            return $num >= $minMax[0] && $num <= $minMax[1];
        }
        return $num >= $size;
    }

    private static function validateAESEncryptedData(ListModel $field, string $v)
    : void
    {
        $decrypted = (new Encryptor())->cryptoPHPJS()->decrypt($v);
        if (!strlen($decrypted)) {
            self::throwError($field);
        }
    }

    public static function validateNumber(ListModel $field, string $v)
    : void
    {
        if (!strlen($v) || !is_numeric($v)) {
            self::throwError($field);
        }
        if ($field->get('size', 0)) {
            if (self::validateSize($field->get('size', 0), strlen($v))) {
                $defaultValue = $field->get('defaultValue', []);
                if (sizeof($defaultValue)) {
                    if (!in_array($v, $defaultValue)) {
                        self::throwError($field);
                    }
                }
            }
            else {
                self::throwError($field);
            }
        }
    }

    public static function validateJSON(ListModel $field, string $v)
    : void
    {
        if (!EncodingUtil::isValidJSON($v)) {
            self::throwError($field);
        }
    }

    public static function validateBoolean(ListModel $field, $v)
    : void
    {
        if ($v === 'true' || $v === 'false') {
            $v = (bool) $v;
        }
        else if ($v === '1' || $v === 1) {
            $v = true;
        }
        else if ($v === '0' || $v === 0) {
            $v = false;
        }
        if ($v !== 'true' && $v !== true && $v !== 'false' && $v !== false) {
            self::throwError($field);
        }
    }

    public static function validateEmail(ListModel $field, string $v)
    : void
    {
        if ($field->get('size', 0)) {
            if (!strlen($v)) {
                self::throwError($field);
            }
            else {
                if (StringUtil::isEmail($v)) {
                    if (!self::validateSize($field->get('size', 0), strlen($v))) {
                        self::throwError($field);
                    }
                }
                else {
                    self::throwError($field);
                }
            }
        }
    }

    public static function validateURL(ListModel $field, string $v)
    : void
    {
        if ($field->get('size', 0)) {
            if (!strlen($v)) {
                self::throwError($field);
            }
            else {
                if (StringUtil::isUrl($v)) {
                    if (!self::validateSize($field->get('size', 0), strlen($v))) {
                        self::throwError($field);
                    }
                }
                else {
                    self::throwError($field);
                }
            }
        }
    }

    public static function validateArray(ListModel $field, $v)
    : void
    {
        if (EncodingUtil::isValidJSON($v)) {
            $v = json_decode($v, true);
        }
        if (is_array($v)) {
            $elements = $field->get('elements', []);
            if (isset($v[0])) {
                foreach ($v as $data) {
                    if (is_string($data) || is_numeric($data) || is_bool($data)) {
                        if (!empty($elements) && !in_array($data, $elements)) {
                            $field = new ListModel(['field'=>$field->getAsArray(), 'value'=>$v]);
                            self::throwError($field);
                        }
                    }
                    else if (is_array($data) && !empty($elements)) {
                        foreach ($elements as $element) {
                            $newField = new ListModel($element);
                            if (isset($data[$newField->get('name')])) {
                                self::validate($newField, $data[$newField->get('name')]);
                            }
                        }
                    }
                    else {
                        self::validate($field, $data);
                    }
                }
            }
            else if (is_array($elements)) {
                if (!empty($elements)) {
                    $n = 0;
                    $j = 0;
                    $element = null;
                    $found = [];
                    $errorFields = [];
                    foreach ($elements as $i => $element) {
                        if (is_array($element) && isset($element['name']) && isset($v[$element['name']])) {
                            $n++;
                            $found[] = $element;
                            self::validate(new ListModel($element), $v[$element['name']]);
                        }
                        else {
                            $errorFields[] = new ListModel([$field->get('name')=>$element]);
                        }
                        $j++;
                    }
                    if ($n !== $j) {
                        $field = new ListModel(['field'=>$errorFields, 'value'=>$v, 'n'=>$n, 'j'=>$j, 'element'=>$element, 'found'=>$found]);
                        self::throwError($field);
                    }
                }
                else {
                    $defaultValue = $field->get('defaultValue', []);
                    if (!empty($defaultValue)) {
                        foreach ($v as $arrVal) {
                            if (!in_array($arrVal, $defaultValue)) {
                                $field = new ListModel(['field'=>$field->getAsArray(), 'value'=>$v]);
                                self::throwError($field);
                            }
                        }
                    }
                }
            }
        }
        else if (is_string($v) || is_numeric($v) || is_null($v) || is_bool($v)) {
            $field = new ListModel(['field'=>$field->getAsArray(), 'value'=>$v]);
            self::throwError($field);
        }
        else if (!empty($field->get('elements', []))) {
            $field = new ListModel(['field'=>$field->getAsArray(), 'value'=>$v]);
            self::throwError($field);
        }
    }

    public static function validateFile(ListModel $field)
    : void
    {
        if (!(isset($_FILES) && is_array($_FILES) && sizeof($_FILES) && isset($_FILES[$field->get('name', '')]))) {
            self::throwError($field);
        }
    }

    public static function validateMixed(ListModel $field, $v)
    : void
    {
        if (!is_null($v)) {
            if (is_numeric($v)) {
                self::validateNumber($field, $v);
            }
            else if (is_string($v)) {
                if (StringUtil::isEmail($v)) {
                    self::validateEmail($field, $v);
                }
                else if (StringUtil::isUrl($v)) {
                    self::validateURL($field, $v);
                }
                else if (EncodingUtil::isValidJSON($v)) {
                    self::validateArray($field, json_decode($v, true));
                }
                else {
                    self::validateString($field, $v);
                }
            }
            else if (is_array($v)) {
                self::validateArray($field, $v);
            }
        }
    }

    public static function validateClientCredentials(ListModel $field, string $v)
    : void
    {
        if ($field->get('size', 0)) {
            if (!strlen($v)) {
                self::throwError($field);
            }
            else {
                // if request length is greater or equal the required length
                if (self::validateSize($field->get('size', 0), strlen($v))) {
                    if (!EncodingUtil::isValidMd5($v)) {
                        self::throwError($field);
                    }
                }
                else {
                    self::throwError($field);
                }
            }
        }
    }
}
