<?php

/**
 * This script listen to AWS SQS queues for incoming input commands
 * It opens the JSON input and starts a execute a callback correcponding to the command
 */

require __DIR__ . '/utils/Utils.php';

class InputPoller
{
    private $debug;
    private $config;
    private $domain;
    private $commandsMap;
    private $CTCom;
    
    function __construct($config)
    {
        global $debug;

        $this->debug  = $debug;
        $this->config = $config;
        $this->domain = $config->{'cloudTranscode'}->{'workflow'}->{'domain'};

        // Init domain. see: Utils.php
        if (!init_domain($this->domain))
            throw new Exception("Unable to init the domain !\n");
        
        // Init workflow. see: Utils.php
        if (!init_workflow($this->config->{'cloudTranscode'}->{'workflow'}))
            throw new Exception("Unable to init the workflow !\n");
        
        // Init eventMap. Maps events with callback functions.
        $this->commandsMap = [
            'StartJob'             => 'start_job',
            'CancelJob'            => 'cancel_job',
            'CancelActivity'       => 'cancel_activity',
            'GetJobList'           => 'get_job_list',
            'GetActivityList'      => 'get_activity_list',
            'GetJobStatus'         => 'get_job_status',
            'GetActivityStatus'    => 'get_activity_status',
        ];

        // Instantiating CloudTranscode Communication SDK.
        // See: https://github.com/sportarchive/CloudTranscodeComSDK
        $this->CTCom = new SA\CTComSDK(false, false, false, $this->debug);
    }

    // Poll from the 'input' SQS queue of all clients
    // If a msg is received, we pass it to 'handle_input' for processing
    public function poll_SQS_queues()
    {
        // For all clients in config files
        // We poll from queues
        foreach ($this->config->{'clients'} as $client)
        {
            // Long Polling messages from client input queue
            $queue = $client->{'queues'}->{'input'};
            try {
                if ($msg = $this->CTCom->receive_message(false, $queue, 2))
                {
                    if (!($decoded = json_decode($msg['Body'])))
                        log_out(
                            "ERROR", 
                            basename(__FILE__), 
                            "JSON data invalid in queue: '$queue'");
                    else                    
                        $this->handle_input($decoded, $client);
                    
                    // Message polled. We delete it from SQS
                    $this->CTCom->delete_message(false, $queue, $msg);
                }
            } catch (Exception $e) {
                log_out(
                    "ERROR", 
                    basename(__FILE__), 
                    $e->getMessage());
            }
        }
    }

    // Receive an input, check if we know the command and exec the callback
    public function handle_input($input, $client)
    {
        $this->validate_input($input);

        // Do we know this input ?
        if (!isset($this->commandsMap[$input->{"command"}]))
        {
            log_out(
                "ERROR", 
                basename(__FILE__), 
                "Command '" . $input->{"command"} . "' is unknown! Ignoring ..."
            );
            return;
        }

        log_out(
            "INFO", 
            basename(__FILE__), 
            "Received command '" . $input->{"command"}  . "'"
        );
        if ($this->debug)
            log_out(
                "INFO", 
                basename(__FILE__), 
                "Details:\n" . json_encode($input, JSON_PRETTY_PRINT)
            );

        // We call the callback function that handles this input command 
        $this->{$this->commandsMap[$input->{"command"}]}($input, $client);
    }

    
    /** 
     * CALLBACKS
     */

    // Start a new workflow in SWF to initiate new transcoding job
    private function start_job($input, $client)
    {
        // SWF client
        global $swf;

        if ($this->debug)
            log_out(
                "DEBUG",
                basename(__FILE__),
                "Starting new workflow!"
            );

        // Workflow info
        $workflowType = array(
            "name"    => $this->config->{'cloudTranscode'}->{'workflow'}->{"name"},
            "version" => $this->config->{'cloudTranscode'}->{'workflow'}->{"version"});
        
        // Append client info to input data
        $input->{"client"} = $client;

        // Request start SWF workflow
        try {
            $workflowRunId = $swf->startWorkflowExecution(array(
                    "domain"       => $this->config->{'cloudTranscode'}->{'workflow'}->{'domain'},
                    "workflowId"   => uniqid('', true),
                    "workflowType" => $workflowType,
                    "taskList"     => array("name" => $this->config->{'cloudTranscode'}->{'workflow'}->{'decisionTaskList'}),
                    "input"        => json_encode($input)
                ));
        } catch (\Aws\Swf\Exception\SwfException $e) {
            log_out(
                "ERROR",
                basename(__FILE__),
                "Unable to start workflow!"
                . $e->getMessage());
        }
    }

    /**
     * UTILS
     */ 

    private function validate_input($input)
    {
        if (!isset($input) || 
            !isset($input->{"data"}) || $input->{"data"} == "" || 
            !isset($input->{"job_id"}) || $input->{"job_id"} == "")
            throw new Exception("'job_id' or 'data' fields missing in JSON input file!");
    }
}


/**
 * INPUT POLLER START
 */

$input_file = "";
$debug = false;

function usage($defaultConfigFile)
{
    echo("Usage: php ". basename(__FILE__) . " [-h] [-c <path to JSON config file>]\n");
    echo("-h: Print this help\n");
    echo("-c <file path>: Optional parameter to override the default configuration file: '$defaultConfigFile'.\n");
    exit(0);
}

function check_input_parameters(&$defaultConfigFile)
{
    global $input_file;
    global $debug;
    
    // Handle input parameters
    $options = getopt("c:hd");
    
    if (isset($options['h']))
        usage($defaultConfigFile);
    
    if (isset($options['d']))
        $debug = true;
    
    if (isset($options['c']))
    {
        log_out(
            "INFO", 
            basename(__FILE__), 
            "Custom config file provided: '" . $options['c'] . "'"
        );
        $defaultConfigFile = $options['c'];
    }
}

// Get config file
$defaultConfigFile = realpath(dirname(__FILE__)) . "/../config/cloudTranscodeConfig.json";
check_input_parameters($defaultConfigFile);
if (!($config = json_decode(file_get_contents($defaultConfigFile))))
{
    log_out(
        "FATAL", 
        basename(__FILE__), 
        "Configuration file '$defaultConfigFile' invalid!"
    );
    exit(1);
}

log_out(
    "INFO", 
    basename(__FILE__), 
    "Domain: '" . $config->{'cloudTranscode'}->{'workflow'}->{'domain'} . "'"
);
log_out(
    "INFO", 
    basename(__FILE__), 
    "TaskList: '" . $config->{'cloudTranscode'}->{'workflow'}->{'decisionTaskList'} . "'"
);
log_out("INFO", basename(__FILE__), $config->{'clients'});

// Create InputPoller object
try {
    $inputPoller = new InputPoller($config);
} 
catch (Exception $e) {
    log_out(
        "FATAL", 
        basename(__FILE__), 
        $e->getMessage()
    );
    exit(1);
}

// Start polling loop to get incoming commands from SQS input queues
while (42)
    $inputPoller->poll_SQS_queues();