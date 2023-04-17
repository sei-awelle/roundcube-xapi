<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xabbuh\XApi\Client\XApiClientBuilder;
use Xabbuh\XApi\Model\Agent;
use Xabbuh\XApi\Model\StatementFactory;
use Xabbuh\XApi\Model\InverseFunctionalIdentifier;
use Xabbuh\XApi\Model\IRI;
use Xabbuh\XApi\Model\Verb;
use Xabbuh\XApi\Model\Activity;
use Xabbuh\XApi\Model\LanguageMap;
use Xabbuh\XApi\Model\Context;
use Xabbuh\XApi\Model\Definition;
use Xabbuh\XApi\Model\IRL;

class xapi extends rcube_plugin
{
    public $rc;
    private $xApiClient;
    private $context;

    public function init()
    {

        $rcmail = rcmail::get_instance();
        $this->rc = &$rcmail;
        $this->add_texts('localization/', true);
        $this->load_config();

        // install user hooks
        //$this->add_hook('message_ready', array($this, 'log_sent_message'));
        $this->add_hook('message_read', [$this, 'log_read_message']);
        $this->add_hook('message_sent', [$this, 'log_sent_message']);
        $this->add_hook('login_after', [$this, 'log_login']);
        //$this->add_hook('storage_connected', [$this, 'log_login']);
        $this->add_hook('refresh', [$this, 'log_refresh']);

    }

    private function build_client()
    {
        // build xapi client
        $this->rcube = rcube::get_instance();
        $this->load_config();
        $config = $this->rcube->config->get('xapi');

        $builder = new XApiClientBuilder();
        $this->xApiClient = $builder->setBaseUrl($config['lrs_endpoint'])
            ->setVersion('1.0.0')
            ->setAuth($config['lrs_username'], $config['lrs_password'])
            ->build();
    }

    private function build_context() {
        $context = new Context();
        $platformContext = $context->withPlatform($_SERVER['SERVER_NAME']);
        $languageContext = $platformContext->withLanguage('en-US');
        //$group = new Group();
        //$context->withTeam($group);
        $this->context = $languageContext;
    }

    private function set_actor($user, $x_user, $sf)
    {
        $agent = new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString("mailto:$user")), $x_user);
        $sf->withActor($agent);
        return $sf;
    }

    private function set_verb($languageMap, $x_verb, $sf)
    {
        //$languageMap = new LanguageMap();
        $mapRead = $languageMap->withEntry("en-US", $x_verb);
        $verb = new Verb(IRI::fromString("https://w3id.org/xapi/dod-isd/verbs/$x_verb"), $mapRead);
        $sf->withVerb($verb);
        return $sf;
    }    

    private function set_object($languageMap, $x_action, $x_search, $sf)
    {
        $mapName = $languageMap->withEntry('en-US', 'Use');
        $mapDesc = $languageMap->withEntry('en-US', $x_action);
        $type = IRI::fromString("http://id.tincanapi.com/activity/login");
        $moreInfo = IRL::fromString('https://' . $_SERVER['SERVER_NAME'] . "?_task=message_history&_action=plugin.message_history&search=$x_search");
        $definition = new Definition($mapName, $mapDesc, $type, $moreInfo);
        $id = IRI::fromString('https://' . $_SERVER['SERVER_NAME']);    
        $activity = new Activity($id, $definition);
        $sf->withObject($activity);

        // Set context
        $sf->withContext($this->context);

        $statement = $sf->createStatement();

        return $statement;

    }

    private function send_statement($statement, $statementsApiClient)
    {
        try {
            $statementsApiClient->storeStatement($statement);
        } catch (Exception $e) {
            $this->xapi_error($e);
        }
    }

    public function log_sent_message($args)
    {
        $db = rcmail::get_instance()->get_dbh();

        //Get user who is actually sending the email
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user->get_username();

        $headers = $args['message']->headers();
        $subject = $headers['Subject'];
        $from_orig = $headers['From'];
        $to_orig = $headers['To'];
        preg_match('/<(.+?)@.+>/', $headers['Message-ID'], $matches);
        $message_id = $matches[1];
        //rcube::console("xapi: $message_id");

        // get just the to emails
        preg_match_all('/<(.+?)>/', $to_orig, $matches);
        //$to_emails = implode(',', $matches);
        $to_emails = $matches[1];

        // get just the to names
        $to_names = preg_replace('/<(.+?)>/', '', $to_orig);
        $to_names = preg_replace('/ , /', ',', $to_names);
        $to_names = trim($to_names);

        // convert from name to email address
        $result = $db->query("SELECT name FROM contacts WHERE email = '$from_orig'");
        if ($db->is_error($result))
        {
            rcube::raise_error([
                'code' => 605, 'line' => __LINE__, 'file' => __FILE__,
                'message' => "xapi: failed to pull name from database."
            ], true, false);
        }
        $records = $db->fetch_assoc($result);
        $from_name = $records['name'];

        // convert to email addresses to names
        $to_names = array();
        foreach ($to_emails as $to_email) {
            $result = $db->query("SELECT name FROM contacts WHERE email = '$to_email'");
            if ($db->is_error($result))
            {
                rcube::raise_error([
                    'code' => 605, 'line' => __LINE__, 'file' => __FILE__,
                    'message' => "xapi: failed to pull name from database."
                ], true, false);
            }
            $records = $db->fetch_assoc($result);
            $to_names[] = $records['name'];
        }

        // build xapi client
        $this->build_client();
        $statementsApiClient = $this->xApiClient->getStatementsApiClient();


        $sf = new StatementFactory();

        // set actor
        $sf->withActor(new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString("mailto:$user")), $from_name));

        // set verb
        $languageMap = new LanguageMap();
        $mapSent = $languageMap->withEntry("en-US", "sent");
        $sf->withVerb(new Verb(IRI::fromString('https://w3id.org/xapi/dod-isd/verbs/sent'), $mapSent));


        // set object
        #$mapName = $languageMap->withEntry('en-US', $subject);
        $mapName = $languageMap->withEntry('en-US', 'Email');
        $mapDesc = $languageMap->withEntry('en-US', 'An email message sent or read during the exercise event');
        $type = IRI::fromString('http://id.tincanapi.com/activitytype/email');
        $moreInfo = IRL::fromString('https://' . $_SERVER['SERVER_NAME'] . "?_task=message_history&_action=plugin.message_history&search=$message_id");
        $definition = new Definition($mapName, $mapDesc, $type, $moreInfo);
        #$imap = "imap://" . $this->rcube->config->get('imap_host');
        #$id = IRI::fromString($imap . "/" . $message_id);
        $id = IRI::fromString('https://' . $_SERVER['SERVER_NAME']);
        $activity = new Activity($id, $definition);
        $sf->withObject($activity);


        // with context
        $sf->withContext($this->context);

        $statement = $sf->createStatement();

        // store a single Statement
        try {
            $statementsApiClient->storeStatement($statement);
        } catch (Exception $e) {
            $this->xapi_error($e);
        }
        return $args;
    }

    public function log_refresh($args)
    {
        // Get user who refreshed
        $db = rcmail::get_instance()->get_dbh();

        //get user who refresjed
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user->get_username();
        $convert_user = $db->query("SELECT name FROM contacts WHERE email = '$user'");
        $records = $db->fetch_assoc($convert_user);
        $refresh_user = $records['name'];

        // Build xapi client
        $this->build_client();
        $statementsApiClient = $this->xApiClient->getStatementsApiClient();

        // Build statement
        $sf = new StatementFactory();

        // Set actor
        //$sf->withActor(new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString("mailto:$user")), $refresh_user));
        $sf = $this->set_actor($user, $refresh_user, $sf);

        // Set verb
        $languageMap = new LanguageMap();
        //$mapLogin = $languageMap->withEntry("en-US", "refresh");
        //$sf->withVerb(new Verb(IRI::fromString('https://w3id.org/xapi/dod-isd/verbs/refresh'), $mapLogin));
        $verb = 'refresh';
        $sf = $this->set_verb($languageMap, $verb, $sf);

        // Set object
        //$mapName = $languageMap->withEntry('en-US', 'Use');
        //$mapDesc = $languageMap->withEntry('en-US', 'A user refreshed during the exercise event');
        // $type = IRI::fromString('http://id.tincanapi.com/activitytype/refresh');
        //$moreInfo = IRL::fromString('https://' . $_SERVER['SERVER_NAME'] . "?_task=message_history&_action=plugin.message_history&search=$user");
        //$definition = new Definition($mapName, $mapDesc, $type, $moreInfo);
        //$id = IRI::fromString('https://' . $_SERVER['SERVER_NAME']);
        //$activity = new Activity($id, $definition);
        //$sf->withObject($activity);

        // Set context
        //$sf->withContext($this->context);

        $action = 'A user refreshed during the exercise event';
        $statement = $this->set_object($languageMap, $action, $user, $sf);
    
        // Send statement
        // $statement = $sf->createStatement();
        //try {
        //$statementsApiClient->storeStatement($statement);
        // } catch (Exception $e) {
        //$this->xapi_error($e);
        //}

        $this->send_statement($statement, $statementsApiClient);

        return $args;
    }

    public function log_login($args)
    {
        // Get user who logged in
        $db = rcmail::get_instance()->get_dbh();

        //get user who logged in
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user->get_username();
        $convert_user = $db->query("SELECT name FROM contacts WHERE email = '$user'");
        $records = $db->fetch_assoc($convert_user);
        $logged_user = $records['name'];

        // Build xapi client
        $this->build_client();
        $statementsApiClient = $this->xApiClient->getStatementsApiClient();

        // Build statement
        $sf = new StatementFactory();

        // Set actor
        //$sf->withActor(new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString("mailto:$user")), $logged_user));
        $sf = $this->set_actor($user, $logged_user, $sf);
        
        // Set verb
        $languageMap = new LanguageMap();
        //$mapLogin = $languageMap->withEntry("en-US", "login");
        //$sf->withVerb(new Verb(IRI::fromString('https://w3id.org/xapi/dod-isd/verbs/login'), $mapLogin));
        $verb = 'login';
        $sf = $this->set_verb($languageMap, $verb, $sf);
        
        // Set object
        //$mapName = $languageMap->withEntry('en-US', 'Use');
        //$mapDesc = $languageMap->withEntry('en-US', 'A user logged in during the exercise event');
        //$type = IRI::fromString('http://id.tincanapi.com/activitytype/login');
        //$moreInfo = IRL::fromString('https://' . $_SERVER['SERVER_NAME'] . "?_task=message_history&_action=plugin.message_history&search=$user");
        //$definition = new Definition($mapName, $mapDesc, $type, $moreInfo);
        //$id = IRI::fromString('https://' . $_SERVER['SERVER_NAME']);    
        //$activity = new Activity($id, $definition);
        //$sf->withObject($activity);
        $action = 'A user logged in during the exercise event';
        $statement = $this->set_object($languageMap, $action, $user, $sf);
        
        // Set context
        //$sf->withContext($this->context);

        // Send statement
        //$statement = $sf->createStatement();
        
        //try {
        //    $statementsApiClient->storeStatement($statement);
        //} catch (Exception $e) {
        //    $this->xapi_error($e);
        //}
        
        $this->send_statement($statement, $statementsApiClient);

        return $args;
    }

    public function log_read_message($args)
    {
        $db = rcmail::get_instance()->get_dbh();
        $message = $args['message'];
        preg_match('/<(.+?)@.+>/', $message->get_header('Message-ID'), $matches);
        $message_id = $matches[1];
        //rcube::console("xapi: " . $message_id);

        //Get user who is actually reading the email
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user->get_username();
        $convert_user = $db->query("SELECT name FROM contacts WHERE email = '$user'");
        $records = $db->fetch_assoc($convert_user);
        $logged_user = $records['name'];

        // Get To User Value
        $to_orig = $message->get_header('to');
        $to_names = preg_replace('/<(.+?)>/', '', $to_orig);
        $to_names = preg_replace('/ , /', ',', $to_names);
        $to_names = trim($to_names);

        // Get From User Value
        $from = $message->get_header('from');
        $convert_from = $db->query("SELECT name FROM contacts WHERE email = '$from'");
        $records = $db->fetch_assoc($convert_from);
        $from_name = $records['name'];

        // Get Subject Value
        $subject = $message->get_header('subject');
        $parsed_subject = substr($subject, strpos($subject, "]") + 2);

        // build xapi client
        $this->build_client();
        $statementsApiClient = $this->xApiClient->getStatementsApiClient();

        $message = $args['message'];

        $sf = new StatementFactory();

        // set actor
        $sf->withActor(new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString("mailto:$user")), $logged_user));

        // set verb
        $languageMap = new LanguageMap();
        $mapRead = $languageMap->withEntry("en-US", "read");
        $sf->withVerb(new Verb(IRI::fromString('https://w3id.org/xapi/dod-isd/verbs/read'), $mapRead));

        // set object
        #$mapName = $languageMap->withEntry('en-US', $parsed_subject);
        $mapName = $languageMap->withEntry('en-US', 'Email');
        $mapDesc = $languageMap->withEntry('en-US', 'An email message sent or read during the exercise event');
        $type = IRI::fromString('http://id.tincanapi.com/activitytype/email');
        $moreInfo = IRL::fromString('https://' . $_SERVER['SERVER_NAME'] . "?_task=message_history&_action=plugin.message_history&search=$message_id");
        $definition = new Definition($mapName, $mapDesc, $type, $moreInfo);
        #$imap = "imap://" . $this->rcube->config->get('imap_host');
        #$id = IRI::fromString($imap . "/" . $message_id);
        $id = IRI::fromString('https://' . $_SERVER['SERVER_NAME']);
        $activity = new Activity($id, $definition);
        $sf->withObject($activity);

        // with context
        $sf->withContext($this->context);

        // create and store statement
        $statement = $sf->createStatement();
        try {
            $statementsApiClient->storeStatement($statement);
        } catch (Exception $e) {
            $this->xapi_error($e);
        }

        return $args;
    }

    private function xapi_error(Exception $e)
    {
        $m = $e->getMessage();
        rcube::console("xapi: " . json_decode($m)->error->message);
        rcube::raise_error([
            'line' => __LINE__,
            'file' => __FILE__,
            'message' => "xapi: $m"
        ], true, false);
    }
}
?>
