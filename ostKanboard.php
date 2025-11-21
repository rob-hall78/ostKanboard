<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class ostKanboard extends Plugin {

    var $config_class = "ostKanboardConfig";
    
    private $created_tickets = array();

    function bootstrap() {
        Signal::connect('object.created', array($this, 'onObjectCreated'));
        Signal::connect('object.edited', array($this, 'onObjectEdited'));
        // Older (pre 1.18) signals
        Signal::connect('ticket.created', array($this, 'onTicketCreated'))
        Signal::connect('ticket.assigned', array($this, 'onTicketAssigned'));
        Signal::connect('ticket.closed', array($this, 'onTicketClosed'));
    }

    function onObjectCreated($object) {
        if ($object instanceof Ticket) {
            $ticket_id = $object->getId();
            
            $status = $object->getStatus();
            if ($status && ($status->getName() == 'Resolved' || $status->getName() == 'Closed')) {
                return;
            }
            
            if (!in_array($ticket_id, $this->created_tickets)) {
                $this->created_tickets[] = $ticket_id;
                
                $dept_id = $object->getDeptId();
                $mapping = $this->getDepartmentMapping($dept_id);
                
                if ($mapping) {
                    $existing_task = $this->getKanboardTaskByReference($object->getNumber(), $mapping['project_id']);
                    
                    if ($existing_task) {
                        return;
                    }
                }
                
                $this->onTicketCreated($object);
            }
        }
    }

    function onObjectEdited($object) {
        if ($object instanceof Ticket) {
            $status = $object->getStatus();
            
            if ($status && ($status->getName() == 'Resolved' || $status->getName() == 'Closed')) {
                $dept_id = $object->getDeptId();
                $mapping = $this->getDepartmentMapping($dept_id);
                
                if ($mapping) {
                    $existing_task = $this->getKanboardTaskByReference($object->getNumber(), $mapping['project_id']);
                    
                    if ($existing_task) {
                        $this->onTicketClosed($object);
                    }
                }
	    }

	    if($object->getAssignee()) {
                $this->onTicketAssigned($object);
            }
        }
    }

    function parseDepartmentMappings() {
        $instances = $this->getActiveInstances();
        if ($instances && $instances->count() > 0) {
            $instance = $instances->first();
            $config = $instance->getConfig();
        } else {
            $config = $this->getConfig();
        }
        
        if (!$config) {
            return array();
        }
        
        $mappings_text = $config->get('webhook-department-mappings');
        if (!$mappings_text) {
            return array();
        }

        $mappings = array();
        $lines = explode("\n", $mappings_text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            $parts = explode(':', $line);
            if (count($parts) >= 4) {
                $dept_id = trim($parts[0]);
                $project_id = trim($parts[1]);
                $new_column_id = trim($parts[2]);
                $done_column_id = trim($parts[3]);
                
                $mappings[$dept_id] = array(
                    'project_id' => $project_id,
                    'new_column_id' => $new_column_id,
                    'done_column_id' => $done_column_id
                );
            }
        }
        
        return $mappings;
    }

    function getDepartmentMapping($dept_id) {
        $mappings = $this->parseDepartmentMappings();
        
        if (isset($mappings[$dept_id])) {
            return $mappings[$dept_id];
        }
        if (isset($mappings[(string)$dept_id])) {
            return $mappings[(string)$dept_id];
        }
        
        return null;
    }

    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        
        if (!$cfg instanceof OsticketConfig) {
            error_log("ostKanboard plugin called too early.");
            return;
        }
        
        $dept_id = $ticket->getDeptId();
        $mapping = $this->getDepartmentMapping($dept_id);
        
        if (!$mapping) {
            return;
        }
        
        $project_id = $mapping['project_id'];
        $column_id = $mapping['new_column_id'];
        
        $instances = $this->getActiveInstances();
        if ($instances && $instances->count() > 0) {
            $instance = $instances->first();
            $config = $instance->getConfig();
        } else {
            $config = $this->getConfig();
        }
        $swimlane_id = $config ? $config->get('webhook-swimlane-id') : '0';
        if (!$swimlane_id) {
            $swimlane_id = '0';
        }
        
        $creator = $ticket->getUser();
        $assignee = $ticket->getAssignee();
        
        $params = array(
            'title' => $ticket->getSubject(),
            'project_id' => (int)$project_id,
            'color_id' => $this->getColorForPriority($ticket->getPriority()),
            'column_id' => (int)$column_id,
            'description' => $this->getTicketDescription($ticket),
            'reference' => $ticket->getNumber(),
            'swimlane_id' => (int)$swimlane_id,
            'priority' => (int)$this->getKanboardPriority($ticket->getPriority())
        );
        
        $this->callKanboardAPI('createTask', $params);
    }

    function onTicketAssigned(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("ostKanboard plugin called too early.");
            return;
        }
        
        $dept_id = $ticket->getDeptId();
        $mapping = $this->getDepartmentMapping($dept_id);
        
	if (!$mapping) {
	    error_log("ostKanboard plugin: no dept mapping for dept_id={$dept_id}");
            return;
        }
    
        $project_id = $mapping['project_id'];
        $task = $this->getKanboardTaskByReference($ticket->getNumber(), $project_id);
        
	if (!$task) {
	    error_log("ostKanboard plugin: no Kanboard task for ticket ".$ticket->getNumber()." in project {$project_id}");
            return;
        }
        
        $assignee = $ticket->getAssignee();
        
        if(!$assignee) {
            $params = array (
                'id' => (int)$task['id'],
                'owner_id' => 0
            );
            $this->callKanboardAPI('updateTask', $params);
            return;
        }

        $username = $assignee->getUserName();

        $params = array (
            'username' => $username
        );

        $result = $this->callKanboardAPI('getUserByName', $params);
        if(!$result || !isset($result['id'])){
            error_log("ostKanboard plugin: getUserByName('{$username}') returned no user");
                return;
            }

        $params = array(
            'id' => (int)$task['id'],
            'owner_id' => (int)$result['id']
        );
        
        $this->callKanboardAPI('updateTask', $params);
    }

    function onTicketClosed(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("ostKanboard plugin called too early.");
            return;
        }
        
        $dept_id = $ticket->getDeptId();
        $mapping = $this->getDepartmentMapping($dept_id);
        
        if (!$mapping) {
            return;
        }
        
        $project_id = $mapping['project_id'];
        $task = $this->getKanboardTaskByReference($ticket->getNumber(), $project_id);
        
        if (!$task) {
            return;
        }
        
        $dst_column_id = $mapping['done_column_id'];
        
        $instances = $this->getActiveInstances();
        if ($instances && $instances->count() > 0) {
            $instance = $instances->first();
            $config = $instance->getConfig();
        } else {
            $config = $this->getConfig();
        }
        $swimlane_id = $config ? $config->get('webhook-swimlane-id') : '0';
        if (!$swimlane_id) {
            $swimlane_id = '0';
        }
        
        $params = array(
            'project_id' => (int)$project_id,
            'task_id' => (int)$task['id'],
            'column_id' => (int)$dst_column_id,
            'position' => 1,
            'swimlane_id' => (int)$swimlane_id
        );
        
        $this->callKanboardAPI('moveTaskPosition', $params);
        
        $this->callKanboardAPI('closeTask', array('task_id' => (int)$task['id']));
    }

    function getKanboardTaskByReference($reference, $project_id) {
        $params = array(
            'project_id' => (int)$project_id
        );
        
        $result = $this->callKanboardAPI('getAllTasks', $params);
        
        if ($result && is_array($result)) {
            foreach ($result as $task) {
                if (isset($task['reference']) && $task['reference'] == $reference) {
                    return $task;
                }
            }
        }
        
        return false;
    }

    function getTicketDescription(Ticket $ticket) {
        $thread = $ticket->getThread();
        if ($thread) {
            $entries = $thread->getEntries();
            if ($entries && count($entries) > 0) {
                return strip_tags($entries[0]->getBody());
            }
        }
        return '';
    }

    function getColorForPriority($priority) {
        if (!$priority) {
            return 'yellow';
        }
        
        $priorityId = $priority->getId();
        
        switch ($priorityId) {
            case 1: 
                return 'green';
            case 2: 
                return 'yellow';
            case 3: 
                return 'orange';
            case 4: 
                return 'red';
            default:
                return 'yellow';
        }
    }

    function getKanboardPriority($priority) {
        if (!$priority) {
            return '0';
        }
        
        $priorityId = $priority->getId();
        
        switch ($priorityId) {
            case 1: 
                return '0';
            case 2: 
                return '1';
            case 3: 
            case 4: 
                return '2';
            default:
                return '1';
        }
    }

    function callKanboardAPI($method, $params = array()) {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("ostKanboard plugin called too early.");
            return false;
        }
        
        $instances = $this->getActiveInstances();
        if ($instances && $instances->count() > 0) {
            $instance = $instances->first();
            $config = $instance->getConfig();
        } else {
            $config = $this->getConfig();
        }
        
        if (!$config) {
            $ost->logError('ostKanboard Plugin Error', 'Unable to access plugin configuration.');
            return false;
        }
        
        $url = $config->get('webhook-api-url');
        $api_key = $config->get('webhook-api-key');
        
        if (!$url || !$api_key) {
            $ost->logError('ostKanboard Plugin not configured', 'You need to configure Kanboard API URL and API Key before using this plugin.');
            return false;
        }

        $request = array(
            'jsonrpc' => '2.0',
            'method' => $method,
            'id' => time(),
            'params' => $params
        );

        $data_string = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );
            curl_setopt($ch, CURLOPT_USERPWD, 'jsonrpc:' . $api_key);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

            $response = curl_exec($ch);
            
            if ($response === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            }
            
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($statusCode != 200) {
                throw new \Exception(
                    'Error calling Kanboard API: ' . $url
                    . ' Http code: ' . $statusCode
                    . ' Response: ' . $response
                    . ' curl-error: ' . curl_errno($ch)
                );
            }
            
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if (isset($result['error'])) {
                throw new \Exception('Kanboard API Error: ' . json_encode($result['error']));
            }
            
            return isset($result['result']) ? $result['result'] : true;
            
        } catch (\Exception $e) {
            $ost->logError('Kanboard API Error', $e->getMessage(), true);
            error_log('Error calling Kanboard API: ' . $e->getMessage());
            if (isset($ch)) {
                curl_close($ch);
            }
            return false;
        }
    }
}

