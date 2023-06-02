<?php
/**
 * Wisp Service Helper
 *
 * @copyright Copyright (c) 2023, MyVariety LLC
 * @license https://www.myvariety.net/legal MyVariety ToS
 * @link https://www.myvariety.net/ MyVariety LLC
 */
class WispService
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
     * Gets a list of parameters to submit to Wisp for user creation
     */
    public function addUserParameters(array $vars)
    {
        Loader::loadModels($this, ['Clients']);
        $client = $this->Clients->get($vars['client_id']);
        return [
            'username' => 'bl_' . $client->id,
            'password' => $this->generatePassword(),
            'email' => $client->email,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'external_id' => 'bl-' . $client->id,
        ];
    }

    /**
     * Generates a password.
     */
    private function generatePassword($min_length = 10, $max_length = 14)
    {
        $pool = 'abcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
        $pool_size = strlen($pool);
        $length = mt_rand(max($min_length, 5), min($max_length, 14));
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= substr($pool, mt_rand(0, $pool_size - 1), 1);
        }

        return $password;
    }

    /**
     * Gets a list of parameters to submit to Wisp for server creation
     */
    public function addServerParameters(array $vars, $package, $wispUser, $wispEgg)
    {
        // Gather server data
        return [
            'external_id' => $vars['client_id'] . '-' . uniqid(),
            'name' => $vars['server_name'],
            'description' => $vars['server_description'],
            'user' => $wispUser->attributes->id,
            'nest' => $package->meta->nest_id,
            'egg' => $package->meta->egg_id,
            'pack' => $package->meta->pack_id,
            'docker_image' => !empty($package->meta->image)
                ? $package->meta->image
                : $wispEgg->attributes->docker_image,
            'startup' => !empty($package->meta->startup)
                ? $package->meta->startup
                : $wispEgg->attributes->startup,
            'limits' => [
                'memory' => $package->meta->memory,
                'swap' => $package->meta->swap,
                'io' => $package->meta->io,
                'cpu' => $package->meta->cpu,
                'disk' => $package->meta->disk,
            ],
            'feature_limits' => [
                'databases' => $package->meta->databases ? $package->meta->databases : null,
                'allocations' => $package->meta->allocations ? $package->meta->allocations : null,
                'backup_megabytes_limit' => $package->meta->backup_megabytes_limit ? $package->meta->backup_megabytes_limit : null,
            ],
            'deploy' => [
                'locations' => [$package->meta->location_id],
                'dedicated_ip' => $package->meta->dedicated_ip,
                'port_range' => explode(',', $package->meta->port_range),
            ],
            'environment' =>  $this->getEnvironmentVariables($vars, $package, $wispEgg),
            'start_on_completion' => true,
        ];
    }

    /**
     * Gets a list of parameters to submit to Wisp for editing server details
     */
    public function editServerParameters(array $vars, $wispUser)
    {
        // Gather server data
        return [
            'external_id' => $vars['client_id'] . '-' . (isset($vars['service_id']) ? $vars['service_id'] : uniqid()),
            'name' => $vars['server_name'],
            'description' => $vars['server_description'],
            'user' => $wispUser->attributes->id,
        ];
    }

    /**
     * Gets a list of parameters to submit to Wisp for editing the server build parameters
     */
    public function editServerBuildParameters($package)
    {
        // Gather server data
        return [
            'limits' => [
                'memory' => $package->meta->memory,
                'swap' => $package->meta->swap,
                'io' => $package->meta->io,
                'cpu' => $package->meta->cpu,
                'disk' => $package->meta->disk,
            ],
            'feature_limits' => [
                'databases' => $package->meta->databases ? $package->meta->databases : null,
                'allocations' => $package->meta->allocations ? $package->meta->allocations : null,
                'backup_megabytes_limit' => $package->meta->backup_megabytes_limit ? $package->meta->backup_megabytes_limit : null,
            ]
        ];
    }

    /**
     * Gets a list of parameters to submit to Wisp for editing server startup parameters
     */
    public function editServerStartupParameters(array $vars, $package, $wispEgg, $serviceFields = null)
    {
        // Gather server data
        return [
            'egg' => $package->meta->egg_id,
            'pack' => $package->meta->pack_id,
            'image' => !empty($package->meta->image)
                ? $package->meta->image
                : $wispEgg->attributes->docker_image,
            'startup' => !empty($package->meta->startup)
                ? $package->meta->startup
                : $wispEgg->attributes->startup,
            'environment' => $this->getEnvironmentVariables($vars, $package, $wispEgg, $serviceFields),
            'skip_scripts' => false,
        ];
    }

    /**
     * Gets a list of environment variables to submit to Wisp
     */
    public function getEnvironmentVariables(array $vars, $package, $wispEgg, $serviceFields = null)
    {
        // Get environment data from the egg
        $environment = [];
        foreach ($wispEgg->attributes->relationships->variables->data as $envVariable) {
            $variableName = $envVariable->attributes->env_variable;
            $blestaVariableName = strtolower($variableName);
            // Set the variable value based on values submitted in the following
            // priority order: config option, service field, package field, Wisp default
            if (isset($vars['configoptions']) && isset($vars['configoptions'][$blestaVariableName])) {
                // Use a config option
                $environment[$variableName] = $vars['configoptions'][$blestaVariableName];
            } elseif (isset($vars[$blestaVariableName])) {
                // Use the service field
                $environment[$variableName] = $vars[$blestaVariableName];
            } elseif (isset($serviceFields) && isset($serviceFields->{$blestaVariableName})) {
                // Reset the previously saved value
                $environment[$variableName] = $serviceFields->{$blestaVariableName};
            } elseif (isset($package->meta->{$blestaVariableName})) {
                // Default to the value set on the package
                $environment[$variableName] = $package->meta->{$blestaVariableName};
            } else {
                // Default to the default value from Wisp
                $environment[$variableName] = $envVariable->attributes->default_value;
            }
        }

        return $environment;
    }

    /**
     * Returns all fields used when adding/editing a service, including any
     * javascript to execute when the page is rendered with these fields.
     */
    public function getFields($wispEgg, $package, $vars = null, $admin = false)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        if ($admin) {
            // Set the server ID
            $serverId = $fields->label(
                Language::_('WispService.service_fields.server_id', true),
                'server_id'
            );
            $serverId->attach(
                $fields->fieldText(
                    'server_id',
                    (isset($vars->server_id) ? $vars->server_id : null),
                    ['id' => 'server_id']
                )
            );
            $tooltip = $fields->tooltip(Language::_('WispService.service_fields.tooltip.server_id', true));
            $serverId->attach($tooltip);
            $fields->setField($serverId);
        }

        // Set the server name
        $serverName = $fields->label(
            Language::_('WispService.service_fields.server_name', true),
            'server_name'
        );
        $serverName->attach(
            $fields->fieldText(
                'server_name',
                (isset($vars->server_name) ? $vars->server_name : null),
                ['id' => 'server_name']
            )
        );
        $tooltip = $fields->tooltip(Language::_('WispService.service_fields.tooltip.server_name', true));
        $serverName->attach($tooltip);
        $fields->setField($serverName);

        // Set the server description
        $serverDescription = $fields->label(
            Language::_('WispService.service_fields.server_description', true),
            'server_description'
        );
        $serverDescription->attach(
            $fields->fieldText(
                'server_description',
                (isset($vars->server_description) ? $vars->server_description : null),
                ['id' => 'server_description']
            )
        );
        $tooltip = $fields->tooltip(Language::_('WispService.service_fields.tooltip.server_description', true));
        $serverDescription->attach($tooltip);
        $fields->setField($serverDescription);

        if ($wispEgg) {
            // Get service fields from the egg
            foreach ($wispEgg->attributes->relationships->variables->data as $envVariable) {
                // Hide the field from clients unless it is marked for display on the package
                $key = strtolower($envVariable->attributes->env_variable);
                if (!$admin
                    && (!isset($package->meta->{$key . '_display'}) || $package->meta->{$key . '_display'} != '1')
                ) {
                    continue;
                }

                // Create a label for the environment variable
                $label = strpos($envVariable->attributes->rules, 'required') === 0
                    ? $envVariable->attributes->name
                    : Language::_('WispService.service_fields.optional', true, $envVariable->attributes->name);
                $field = $fields->label($label, $key);
                // Create the environment variable field and attach to the label
                $field->attach(
                    $fields->fieldText(
                        $key,
                        (isset($vars->{$key})
                            ? $vars->{$key}
                            : (isset($package->meta->{$key})
                                ? $package->meta->{$key}
                                : $envVariable->attributes->default_value
                            )
                        ),
                        ['id' => $key]
                    )
                );
                // Add tooltip based on the description from Wisp
                $tooltip = $fields->tooltip($envVariable->attributes->description);
                $field->attach($tooltip);
                // Set the label as a field
                $fields->setField($field);
            }
        }

        $egg_id = isset($package->configurable_options['egg_id']) ? $package->configurable_options['egg_id'] : 0;
        $nest_id = isset($package->configurable_options['nest_id']) ? $package->configurable_options['nest_id'] : 0;
        $location_id = isset($package->configurable_options['location_id'])
            ? $package->configurable_options['location_id']
            : 0;
        // Set js to refetch options when the nest or egg is changed
        $fields->setHtml("
            <script type=\"text/javascript\">
                $(document).ready(function() {
                    // Re-fetch module options to pull in eggs and egg variables
                    // when a nest or egg respectively is selected
                    $('.package_options').on(
                        'focusout',
                        '*[name=\"configoptions[$egg_id]\"], *[name=\"configoptions[$nest_id]\"], *[name=\"configoptions[$location_id]\"]',
                        function() {
                            var form = $(this).closest('form');
                            $(form).append('<input type=\"hidden\" name=\"refresh_fields\" value=\"true\">');
                            $(form).submit();
                        }
                    );
                });
            </script>
        ");

        return $fields;
    }

    /**
     * Returns the rule set for adding/editing a service
     */
    public function getServiceRules(array $vars = null, $package = null, $edit = false, $wispEgg = null)
    {
        // Set rules
        $rules = [
            'server_name' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('WispService.!error.server_name.empty', true)
                ]
            ]
        ];

        // Get the rule helper
        Loader::load(dirname(__FILE__). DS . 'wisp_rule.php');
        $rule_helper = new WispRule();

        // Get egg variable rules
        if ($wispEgg) {
            foreach ($wispEgg->attributes->relationships->variables->data as $envVariable) {
                $fieldName = strtolower($envVariable->attributes->env_variable);
                $rules[$fieldName] = $rule_helper->parseEggVariable($envVariable);

                foreach ($rules[$fieldName] as $rule) {
                    if (array_key_exists('if_set', $rule)
                        && $rule['if_set'] == true
                        && empty($vars[$fieldName])
                    ) {
                        unset($rules[$fieldName]);
                    }
                }
            }
        }

        return $rules;
    }
}