<?php
class xapi extends rcube_plugin
{
	public $rc;

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

	public function xapi_init()
	{
		$this->register_handler('plugin.body', array($this, 'logs_form'));
		$this->rc->output->set_pagetitle($this->gettext('logs'));
		$this->rc->output->send('plugin');
	}

	public function log_sent_message($args)
	{
		$this->load_config();
		$rcube = rcube::get_instance();
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
/*
		if ($db->is_error($result))
		{
			rcube::raise_error([
				'code' => 605, 'line' => __LINE__, 'file' => __FILE__, 
				'message' => "xapi: failed to insert record into database."
			], true, false);
		}
*/		
		return $args;
	}



}
?>
