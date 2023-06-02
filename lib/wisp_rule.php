<?php
use Blesta\Core\Util\Validate\Server;
/**
 * Wisp Rule Helper
 *
 * @copyright Copyright (c) 2023, MyVariety LLC
 * @license https://www.myvariety.net/legal MyVariety ToS
 * @link https://www.myvariety.net/ MyVariety LLC
 */
class WispRule
{
    /**
     * Parses an egg variable from Wisp and returns a Blesta input validation rule array
     */
    public function parseEggVariable($eggVariable)
    {
        // Get the name of the field being validated
        $fieldName = $eggVariable->attributes->name;

        // Parse rule string for regexes and remove them to simplify parsing
        $ruleString = $eggVariable->attributes->rules;

        // Match the regex strings and store them in an array
        $regexRuleStrings = [];
        preg_match_all("/regex\\:(\\/.*?\\/)/i", $ruleString, $regexRuleStrings);

        // Remove the regex stings and replace them with {{{regex}}}
        $regexFilteredRuleString = str_replace($regexRuleStrings[1] ?? [], '{{{regex}}}', $ruleString);

        // Get a the list of OR separated validation rules
        $ruleStrings = explode('|', $regexFilteredRuleString);

        // Parse rules from the string
        $rules = [];
        foreach ($ruleStrings as $ruleString) {
            $ruleParts = explode(':', $ruleString);
            $ruleName = str_replace('_', '', lcfirst(ucwords($ruleParts[0], '_')));

            $ruleParameters = [];
            if (isset($ruleParts[1])) {
                $ruleParameters = explode(',', $ruleParts[1]);
            }

            // Re-add filtered regexes
            if (!empty($regexRuleStrings[1])) {
                foreach ($ruleParameters as &$ruleParameter) {
                    $ruleParameter = str_replace('{{{regex}}}', array_shift($regexRuleStrings[1]), $ruleParameter);
                }
            }

            // Generate validation rule
            if (method_exists($this, $ruleName)) {
                $rules[$ruleName] = call_user_func_array(
                    [$this, $ruleName],
                    [$fieldName, $ruleParameters]
                );
            }
        }

        // Make all rules conditional on field existence
        if (strpos($eggVariable->attributes->rules, 'required') === false) {
            foreach ($rules as &$rule) {
                $rule['if_set'] = true;
            }
        }

        return $rules;
    }

    /**
     * Gets a rule to require the given field
     */
    private function required($fieldName)
    {
        return [
            'rule' => 'isEmpty',
            'negate' => true,
            'message' => Language::_('WispRule.!error.required', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field against a regex
     */
    private function regex($fieldName, array $params)
    {
        return [
            'rule' => ['matches', $params[0]],
            'message' => Language::_('WispRule.!error.regex', true, $fieldName, $params[0])
        ];
    }

    /**
     * Gets a rule to validate the given field is numeric
     */
    private function numeric($fieldName)
    {
        return [
            'rule' => 'is_numeric',
            'message' => Language::_('WispRule.!error.numeric', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field is an integer
     */
    private function integer($fieldName)
    {
        return [
            'rule' => function ($value) {
                return is_numeric($value) && intval($value) == $value;
            },
            'message' => Language::_('WispRule.!error.integer', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field is a string
     */
    private function string($fieldName)
    {
        return [
            'rule' => 'is_string',
            'message' => Language::_('WispRule.!error.string', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field is alpha_numeric
     */
    private function alphaNum($fieldName)
    {
        return [
            'rule' => 'ctype_alnum',
            'message' => Language::_('WispRule.!error.alphaNum', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field is only alpha_numeric characters or dashes and underscores
     */
    private function alphaDash($fieldName)
    {
        return [
            'rule' => function($value) {
                return ctype_alnum(preg_replace('/-|_/', '', $value));
            },
            'message' => Language::_('WispRule.!error.alphaDash', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field is a valid URL
     */
    private function url($fieldName)
    {
        return [
            'rule' => function($value) {
                $validator = new Server();
                return $validator->isUrl($value);
            },
            'message' => Language::_('WispRule.!error.url', true, $fieldName)
        ];
    }

    /**
     * Gets a rule to validate the given field has a value with a given minimum
     */
    private function min($fieldName, array $params)
    {
        return [
            'rule' => function ($value) use ($params) {
                switch (gettype($value)) {
                    case 'string':
                        return strlen($value) >= $params[0];
                    case 'integer':
                        // Same as double
                    case 'double':
                        return $value >= $params[0];
                    case 'array':
                        return count($value) >= $params[0];
                }
            },
            'message' => Language::_('WispRule.!error.min', true, $fieldName, $params[0])
        ];
    }

    /**
     * Gets a rule to validate the given field has a value with a given maximum
     */
    private function max($fieldName, array $params)
    {
        return [
            'rule' => function ($value) use ($params) {
                switch (gettype($value)) {
                    case 'string':
                        return strlen($value) <= $params[0];
                    case 'integer':
                        // Same as double
                    case 'double':
                        return $value <= $params[0];
                    case 'array':
                        return count($value) <= $params[0];
                }
            },
            'message' => Language::_('WispRule.!error.max', true, $fieldName, $params[0])
        ];
    }

    /**
     * Gets a rule to validate the given field has a value within a given range
     */
    private function between($fieldName, array $params)
    {
        return [
            'rule' => function ($value) use ($params) {
                switch (gettype($value)) {
                    case 'string':
                        return strlen($value) >= $params[0] && strlen($value) <= $params[1];
                    case 'integer':
                        // Same as double
                    case 'double':
                        return $value >= $params[0] && $value <= $params[1];
                    case 'array':
                        return count($value) >= $params[0] && count($value) <= $params[1];
                }
            },
            'message' => Language::_('WispRule.!error.between', true, $fieldName, $params[0], $params[1])
        ];
    }

    /**
     * Gets a rule to validate the given field has a numeric value within a given range
     */
    private function digitsBetween($fieldName, array $params)
    {
        return [
            'rule' => function ($value) use ($params) {
                return is_numeric($value) && strlen($value) >= $params[0] && strlen($value) <= $params[1];
            },
            'message' => Language::_('WispRule.!error.digitsBetween', true, $fieldName, $params[0], $params[1])
        ];
    }
}
