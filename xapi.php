<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xabbuh\XApi\Client\XApiClientBuilder;
use Xabbuh\XApi\Model\Statement;
use Xabbuh\XApi\Model\Actor;
use Xabbuh\XApi\Model\Agent;
use Xabbuh\XApi\Model\StatementFactory;
use Xabbuh\XApi\Model\InverseFunctionalIdentifier;
use Xabbuh\XApi\Model\IRI;
use Xabbuh\XApi\Model\Verb;
use Xabbuh\XApi\Model\Activity;



class xapi extends rcube_plugin
{
	public $rc;
	private $xApiClient;

    	public function init()
	{

		$rcmail = rcmail::get_instance();
		$this->rc = &$rcmail;
		$this->add_texts('localization/', true);
		$this->load_config();

		// install user hooks
		$this->add_hook('message_ready', array($this, 'log_sent_message'));
		$this->add_hook('message_read', [$this, 'log_read_message']);
		//$this->add_hook('message_sent', [$this, 'log_sent_message']);
		//$this->add_hook('login_after', [$this, 'log_login']);
		//$this->add_hook('oauth_login', [$this, 'log_oauth_login']);
		//$this->add_hook('refresh', [$this, 'log_refresh']);
		
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

	public function log_sent_message($args)
	{
		$db = rcmail::get_instance()->get_dbh();

		$headers = $args['message']->headers();
		$subject = $headers['Subject'];
		$from_orig = $headers['From'];
		$to_orig = $headers['To'];
		$time_sent = $headers['Date'];

		// get just the to emails
		preg_match('/<(.+?)>/', $to_orig, $matches);
		$to_emails = implode(',', $matches);

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

		// build xapi client
		$this->build_client();
		$statementsApiClient = $this->xApiClient->getStatementsApiClient();

		$actor = "arwelle";
		$verb = "read";
		$object = $args['message'];
		//$statement = new Statement(null, $actor, $verb, $object);

		// store a single Statement
		//$statementsApiClient->storeStatement($statement);

		return $args;
	}



	public function log_read_message($args)
	{
		$db = rcmail::get_instance()->get_dbh();
		$message = $args['message'];


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
	        $to_array = explode(',', $to_names);

	        // Get From User Value
	        $from = $message->get_header('from');
	        $convert_from = $db->query("SELECT name FROM contacts WHERE email = '$from'");
	        $records = $db->fetch_assoc($convert_from);
	        $from_name = $records['name'];

	        // Get Subject Value
	        $subject = $message->get_header('subject');
	        $parsed_subject = substr($subject, strpos($subject, "]") + 2);
	        // Get Date Value
	        $date_str = $message->get_header('Date');
	        $timestamp = date('Y-m-d H:i:s', strtotime($date_str)) . '+00';

                // build xapi client
                $this->build_client();
                $statementsApiClient = $this->xApiClient->getStatementsApiClient();

		foreach ($to_array as $to) {
			$to = trim($to);


			$actor = "arwelle";
			$verb = "read";
			$object = $args['message'];
			$sf = new StatementFactory();
			$sf->withActor(new Agent(InverseFunctionalIdentifier::withMbox(IRI::fromString('mailto:arwelle@cert.org'))));
		        $sf->withVerb(new Verb(IRI::fromString('http://tincanapi.com/conformancetest/verbid')));
		        $sf->withObject(new Activity(IRI::fromString('http://tincanapi.com/conformancetest/activityid')));

			$statement = $sf->createStatement();
			//$statement = new Statement(null, $actor, $verb, $object);

			// store a single Statement
			$statementsApiClient->storeStatement($statement);
		}
		// TODO maye all statements to array and send multiple at once right here

		return $args;
	}



}
?>
