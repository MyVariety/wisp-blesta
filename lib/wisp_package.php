<?php
/**
 * Wisp Package Helper
 *
 * @copyright Copyright (c) 2023, MyVariety LLC
 * @license https://www.myvariety.net/legal MyVariety ToS
 * @link https://www.myvariety.net/ MyVariety LLC
 */
class WispPackage
{
    /**
     * Initialize
     */
    public function __construct()
    {
        // Load required components
        Loader::loadComponents($this, ['Input']);
    }

    /**
     * Retrieves a list of Input errors, if any
     */
    public function errors()
    {
        return $this->Input->errors();
    }

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     */
    public function add(array $packageLists, array $vars = null)
    {
        // Set missing checkboxes
        $checkboxes = ['dedicated_ip'];
        foreach ($checkboxes as $checkbox) {
            if (empty($vars['meta'][$checkbox])) {
                $vars['meta'][$checkbox] = '0';
            }
        }

        // Get the rule helper
        Loader::load(dirname(__FILE__) . DS . 'wisp_rule.php');
        $rule_helper = new WispRule();

        $rules = $this->getRules($packageLists, $vars);
        // Get egg variable rules
        if (isset($vars['meta']['egg_id']) && isset($packageLists['eggs'][$vars['meta']['egg_id']])) {
            $egg = $packageLists['eggs'][$vars['meta']['egg_id']];
            foreach ($egg->attributes->relationships->variables->data as $envVariable) {
                $fieldName = strtolower($envVariable->attributes->env_variable);
                $rules['meta[' . $fieldName . ']'] = $rule_helper->parseEggVariable($envVariable);

                foreach ($rules['meta[' . $fieldName . ']'] as $rule) {
                    if (array_key_exists('if_set', $rule)
                        && $rule['if_set'] == true
                        && empty($vars['meta'][$fieldName])
                    ) {
                        unset($rules['meta[' . $fieldName . ']']);
                    }
                }
            }
        }

        // Set rules to validate input data
        $this->Input->setRules($rules);

        // Build meta data to return
        $meta = [];
        if ($this->Input->validates($vars)) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     */
    public function getFields(array $packageLists, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        // Set js to refetch options when the nest or egg is changed
        $fields->setHtml("
			<script type=\"text/javascript\">
				$(document).ready(function() {
					// Re-fetch module options to pull in eggs and egg variables
                    // when a nest or egg respectively is selected
					$('#Wisp_nest_id, #Wisp_egg_id').change(function() {
						fetchModuleOptions();
					});
				});
			</script>
		");

        // Set the select fields
        $selectFields = [
            'location_id' => isset($packageLists['locations']) ? $packageLists['locations'] : [],
            'nest_id' => isset($packageLists['nests']) ? $packageLists['nests'] : [],
            'egg_id' => isset($packageLists['eggs'])
                ? array_combine(array_keys($packageLists['eggs']), array_keys($packageLists['eggs']))
                : [],
        ];
        foreach ($selectFields as $selectField => $list) {
            // Create the select field label
            $field = $fields->label(
                Language::_('WispPackage.package_fields.' . $selectField, true),
                'Wisp_' . $selectField
            );
            // Set the select field
            $field->attach(
                $fields->fieldSelect(
                    'meta[' . $selectField . ']',
                    $list,
                    $this->Html->ifSet($vars->meta[$selectField]),
                    ['id' => 'Wisp_' . $selectField]
                )
            );
            // Add a tooltip based on the select field
            $tooltip = $fields->tooltip(Language::_('WispPackage.package_fields.tooltip.' . $selectField, true));
            $field->attach($tooltip);
            $fields->setField($field);
        }

        // Set the Dedicated IP
        $dedicatedIp = $fields->label(
            Language::_('WispPackage.package_fields.dedicated_ip', true),
            'Wisp_dedicated_ip',
            ['class' => 'inline']
        );
        $dedicatedIp->attach(
            $fields->fieldCheckbox(
                'meta[dedicated_ip]',
                '1',
                $this->Html->ifSet($vars->meta['dedicated_ip']) == 1,
                ['id' => 'Wisp_dedicated_ip', 'class' => 'inline']
            )
        );
        $tooltip = $fields->tooltip(Language::_('WispPackage.package_fields.tooltip.dedicated_ip', true));
        $dedicatedIp->attach($tooltip);
        $fields->setField($dedicatedIp);

        // Set text fields
        $textFields = [
            'port_range', 'pack_id', 'memory', 'swap', 'cpu', 'disk',
            'io', 'startup', 'image', 'databases', 'allocations', 'backup_megabytes_limit'
        ];
        foreach ($textFields as $textField) {
            // Create the text field label
            $field = $fields->label(
                Language::_('WispPackage.package_fields.' . $textField, true),
                'Wisp_' . $textField
            );
            // Set the text field
            $field->attach(
                $fields->fieldText(
                    'meta[' . $textField . ']',
                    $this->Html->ifSet($vars->meta[$textField]),
                    ['id' => 'Wisp_' . $textField]
                )
            );
            // Add a tooltip based on the text field
            $tooltip = $fields->tooltip(Language::_('WispPackage.package_fields.tooltip.' . $textField, true));
            $field->attach($tooltip);
            $fields->setField($field);
        }

        // Return standard package fields and attach any applicable egg fields
        return isset($packageLists['eggs'][$this->Html->ifSet($vars->meta['egg_id'])])
            ? $this->attachEggFields($packageLists['eggs'][$this->Html->ifSet($vars->meta['egg_id'])], $fields, $vars)
            : $fields;
    }

    /**
     * Attaches package fields for each environment from the Wisp egg
     */
    private function attachEggFields($wisp_egg, $fields, $vars = null)
    {
        if (!is_object($wisp_egg)) {
            return $fields;
        }

        // Get service fields from the egg
        foreach ($wisp_egg->attributes->relationships->variables->data as $env_variable) {
            // Create a label for the environment variable
            $label = strpos($env_variable->attributes->rules, 'required') === 0
                ? $env_variable->attributes->name
                : Language::_('WispPackage.package_fields.optional', true, $env_variable->attributes->name);
            $key = strtolower($env_variable->attributes->env_variable);
            $field = $fields->label($label, $key);
            // Create the environment variable field and attach to the label
            $field->attach(
                $fields->fieldText(
                    'meta[' . $key . ']',
                    $this->Html->ifSet($vars->meta[$key], $env_variable->attributes->default_value),
                    ['id' => $key]
                )
            );
            // Add tooltip based on the description from Wisp
            $tooltip = $fields->tooltip(
                $env_variable->attributes->description
                . ' '
                . Language::_('WispPackage.package_fields.tooltip.display', true)
            );
            // Create a field for whether to display the environment variable to the client
            $checkboxKey = $key . '_display';
            $field->attach($tooltip);
            $field->attach(
                $fields->fieldCheckbox(
                    'meta[' . $checkboxKey . ']',
                    '1',
                    $this->Html->ifSet($vars->meta[$checkboxKey], '0') == '1',
                    ['id' => $checkboxKey, 'class' => 'inline']
                )
            );
            // Set the label as a field
            $fields->setField($field);
        }

        return $fields;
    }

    /**
     * Builds and returns the rules required to add/edit a package
     */
    public function getRules(array $packageLists, array $vars)
    {
        $rules = [
            'meta[location_id]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[location_id].format', true)
                ],
                'valid' => [
                    'rule' => [
                        'array_key_exists',
                        isset($packageLists['locations']) ? $packageLists['locations'] : []
                    ],
                    'message' => Language::_('WispPackage.!error.meta[location_id].valid', true)
                ]
            ],
            'meta[dedicated_ip]' => [
                'format' => [
                    'rule' => ['in_array', [0, 1]],
                    'message' => Language::_('WispPackage.!error.meta[dedicated_ip].format', true)
                ]
            ],
            'meta[port_range]' => [
                'format' => [
                    'rule' => function ($portRanges) {
                        $ranges = explode(',', $portRanges);
                        foreach ($ranges as $range) {
                            if (!preg_match('/^[0-9]+\-[0-9]+$/', $range)) {
                                return false;
                            }
                        }

                        return true;
                    },
                    'message' => Language::_('WispPackage.!error.meta[port_range].format', true)
                ]
            ],
            'meta[nest_id]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[nest_id].format', true)
                ],
                'valid' => [
                    'rule' => [
                        'array_key_exists',
                        isset($packageLists['nests']) ? $packageLists['nests'] : []
                    ],
                    'message' => Language::_('WispPackage.!error.meta[nest_id].valid', true)
                ]
            ],
            'meta[egg_id]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[egg_id].format', true)
                ],
                'valid' => [
                    'rule' => [
                        'array_key_exists',
                        isset($packageLists['eggs']) ? $packageLists['eggs'] : []
                    ],
                    'message' => Language::_('WispPackage.!error.meta[egg_id].valid', true)
                ]
            ],
            'meta[pack_id]' => [
                'format' => [
                    'rule' => function ($packId) {
                        return empty($packId) || preg_match('/^[0-9]+$/', $packId);
                    },
                    'message' => Language::_('WispPackage.!error.meta[pack_id].format', true)
                ]
            ],
            'meta[memory]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[memory].format', true)
                ]
            ],
            'meta[swap]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[swap].format', true)
                ]
            ],
            'meta[cpu]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[cpu].format', true)
                ]
            ],
            'meta[disk]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[disk].format', true)
                ]
            ],
            'meta[io]' => [
                'format' => [
                    'rule' => ['matches', '/^[0-9]+$/'],
                    'message' => Language::_('WispPackage.!error.meta[io].format', true)
                ]
            ],
            'meta[image]' => [
                'length' => [
                    'rule' => ['maxLength', 255],
                    'message' => Language::_('WispPackage.!error.meta[image].length', true)
                ]
            ],
            'meta[databases]' => [
                'format' => [
                    'rule' => function ($databaseLimit) {
                        return empty($databaseLimit) || preg_match('/^[0-9]+$/', $databaseLimit);
                    },
                    'message' => Language::_('WispPackage.!error.meta[databases].format', true)
                ]
            ],
            'meta[allocations]' => [
                'format' => [
                    'rule' => function ($allocationLimit) {
                        return empty($allocationLimit) || preg_match('/^[0-9]+$/', $allocationLimit);
                    },
                    'message' => Language::_('WispPackage.!error.meta[allocations].format', true)
                ]
            ],
            'meta[backup_megabytes_limit]' => [
                'format' => [
                    'rule' => function ($backupMegabytesLimit) {
                        return empty($backupMegabytesLimit) || preg_match('/^[0-9]+$/', $backupMegabytesLimit);
                    },
                    'message' => Language::_('WispPackage.!error.meta[backup_megabytes_limit].format', true)
                ]
            ],
        ];

        return $rules;
    }
}