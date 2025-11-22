<?php

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class ostKanboardConfig extends PluginConfig {

    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                },
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
            );
        }
        return Plugin::translate('ostkanboard');
    }

    function getOptions() {
        list ($__, $_N) = self::translate();

        return array(
            'ostKanboard'                      => new SectionBreakField(array(
                'hint'  => $__('<br>Configure Kanboard API integration. This plugin will send ticket events to Kanboard via JSON-RPC API.')
                    )),
            'ostKanboard-api-url'              => new TextboxField(array(
                'label'         => $__('Kanboard API URL'),
                'required'      => true,
                'default'       => '',
                'placeholder'   => "http://kanboard.domain.com/jsonrpc.php",
                'configuration' => array(
                    'size'   => 130,
                    'length' => 300
                )
                    )),
            'ostKanboard-api-key'              => new TextboxField(array(
                'label'         => $__('Kanboard API Key'),
                'required'      => true,
                'default'       => '',
                'placeholder'   => "Your API key from Kanboard settings",
                'configuration' => array(
                    'size'   => 130,
                    'length' => 300
                )
                    )),
            'ostKanboard-department-mappings'  => new TextareaField(array(
                'label'         => $__('Department to Project Mappings'),
                'required'      => true,
                'default'       => '',
                'placeholder'   => "dept_id:project_id:new_column_id:done_column_id\n1:1:2:1\n2:3:2:5",
                'hint'          => $__('Map osTicket department IDs to Kanboard project and column IDs. Format: dept_id:project_id:new_column_id:done_column_id (one per line). Only tickets from mapped departments will trigger API calls.'),
                'configuration' => array(
                    'rows'   => 10,
                    'cols'   => 60,
                    'html'   => false
                )
                    )),
            'ostKanboard-swimlane-id'          => new TextboxField(array(
                'label'         => $__('Default Swimlane ID'),
                'required'      => false,
                'default'       => '0',
                'placeholder'   => "0",
                'hint'          => $__('Default swimlane ID (0 for default)'),
                'configuration' => array(
                    'size'   => 10,
                    'length' => 10
                )
                    ))
        );
    }

}
