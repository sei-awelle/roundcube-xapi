<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xabbuh\XApi\Client\XApiClientBuilder;
use Xabbuh\XApi\Model\Statement;

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
		//$this->add_hook('message_read', [$this, 'log_read_message']);
		
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

		$statement = new Statement(null, $actor, $verb, $object);

		// store a single Statement
		$statementsApiClient->storeStatement($statement);

		return $args;
	}



}
?>
