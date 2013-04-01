<?php

/**
 * Integration with Infinite Rooms analytics engine.
 */
abstract class InfiniteRoomsIntegration {

	private $last_sync_time;
	
	protected abstract function get_config($key);
	protected abstract function set_config($key, $value);

	protected function get_users($since_time) { return null; }
	protected function get_modules($since_time) { return null; }
	protected function get_actions($since_time, $limit) { return null; }

	/**
	 * Main function, extract the data and send it to Infinite Rooms for processing.
	 */
	public function sync($limit = 10000) {
		set_time_limit(300); // increase time limit to 5 minutes
		$since_time = $this->get_last_sync();
		$this->send("import/user", $this->get_users($since_time));
		$this->send("import/module", $this->get_modules($since_time));
		return $this->send("import/action", $this->get_actions($since_time, $limit));
	}

	public function sync_full() {
		// this can take a very long time to return
		$batch_size = 10000;
		while ($this->sync($batch_size) == $batch_size) {
			// keep processing
		}
	}

	protected function get_site_name() {
		return php_uname('n');
	}

	protected function get_site_contact() {
		return null;
	}

	protected function get_credentials() {
		$creds = $this->get_config('credentials');
		if (empty($creds)) {
			$creds = $this->create_credentials();
			$this->set_config('credentials', $creds);
		}
		return $creds;
	}

	protected function get_infiniterooms_url() {
		$url = $this->get_config('infiniterooms');
		if (empty($url)) {
			$url = 'https://www.infiniterooms.co.uk';
			$this->set_config('infiniterooms', $url);
		}
		return $url;
	}

	/**
	 * A remote call to the Infinite Rooms API, using a cilent-side certificate for authentication.
	 */
	protected function remote_call($method, $uri, $payload = null) {
		$url = $this->get_infiniterooms_url();
		$url = rtrim($url, '/') . '/api/' . $uri;

		$creds = $this->get_credentials();
		$creds_file = tempnam(sys_get_temp_dir(), "infiniterooms-cert");
		file_put_contents($creds_file, $creds);

		$ch = curl_init($url);
		#curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
		#curl_setopt($ch, CURLOPT_HEADER, TRUE);
		curl_setopt($ch, CURLOPT_SSLCERT, $creds_file);
		curl_setopt($ch, CURLOPT_SSLKEY, $creds_file);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		#curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
		#curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		#curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		
		if ($method == 'PUT') {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Transfer-Encoding: chunked'));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($ch, CURLOPT_UPLOAD, TRUE);
			curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($payload) {
			    return fread($payload, $length) ?: '';
			});
		}

		$result = curl_exec($ch);
		if ($result === FALSE) die("Remote call to $url failed: " . curl_error($ch));

		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($status != 200) die("Remote call to $url failed with code $status");

		curl_close($ch);
		unlink($creds_file);

		return $result;
	}

	public function create_credentials($cn = NULL) {
		# find an approperiate common name
		if (empty($cn)) $cn = $this->get_site_name();

		# build the dn
		$dn = array(
			"countryName" => 'GB',
			"organizationName" => 'Infinite Rooms',
			"organizationalUnitName" => 'Client',
			"commonName" => $cn
		);

		# include a contact email if one is available
		$email = $this->get_site_contact();
		if (!is_null($email)) $dn['emailAddress'] = $email;

		# certificate configuration options
		$openssl_config = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'openssl.cnf';
		$lifespan = 365 * 10;
		$configargs = array(
			'config' => $openssl_config,
			'private_key_bits' => 2048,
			
		);

		#print "dn: " . implode(',', $dn) . "\n";
		#print "openssl config: $openssl_config\n";

		$keypair = openssl_pkey_new($configargs);
		$csr = openssl_csr_new($dn, $keypair, $configargs);
		if (!$csr) die('Unable to generate certificate signing request: ' . openssl_error_string());
		$sscert = openssl_csr_sign($csr, null, $keypair, $lifespan);
		openssl_x509_export($sscert, $publickey);
		openssl_pkey_export($keypair, $privatekey, NULL);

		#print "publickey: $publickey\n";
		#print "privatekey: $privatekey\n";

		// return certificate and private key
		$creds = $publickey . $privatekey;
		return $creds;
	}

	public function ping() {
		return $this->remote_call('GET', 'ping');
	}

	public function authenticated_ping() {
		return $this->remote_call('GET', 'ping/authenticated');
	}

	public function get_last_sync() {
		if (is_null($this->last_sync_time)) {
			$this->last_sync_time = $this->get_last_sync_now();
		}
		return $this->last_sync_time;
	}

	public function get_last_sync_now() {
		$last_entry_unixtime = 0;
		$last_entry_rfc3339 = $this->remote_call('GET', 'import/last-updated');
		if (!empty($last_entry_rfc3339)) {
			$last_entry_datetime = DateTime::createFromFormat(DateTime::RFC3339, $last_entry_rfc3339, new DateTimeZone('UTC'));
			$last_entry_unixtime = $last_entry_datetime->getTimestamp();
		}
		return $last_entry_unixtime;
	}

	protected function convert_date($format_in, $format_out, $datetime_in) {
		$utc = new DateTimeZone('UTC');
		$datetime = DateTime::createFromFormat($format_in, $datetime_in, $utc);
		if (!$datetime) {
			$errors = DateTime::getLastErrors();
			header('HTTP/1. 400 Bad Request');
			die("Failed to parse date $datetime_in as " . $format_in . " due to " . $errors['errors'][0]);
		}
		$datetime->setTimezone($utc);
		return $datetime->format($format_out);
	}

	public function send($target, $rs) {
		if (is_null($rs)) return 0;

		if (empty($rs)) {
			if (method_exists($rs, 'close')) $rs->close();
			return 0;
		}

		// Ideally we should be streaming this to reduce memory requirements
		$buffer = fopen('php://temp', 'w+b');
		//stream_filter_append($buffer, "zlib.deflate", STREAM_FILTER_WRITE);

		$fields = null;
		$record_count = 0;
		foreach ($rs as $record) {
			$record = (object)$record;
			$record_count++;

			if (is_null($fields)) {
				$fields = array_keys(get_object_vars($record));
				fwrite($buffer, implode(',', $fields));
				fwrite($buffer, "\n");
			}

			$values = array();
			foreach ($fields as $field) {
				array_push($values, $record->{$field});
			}

			fputcsv($buffer, $values);
		}
		if (method_exists($rs, 'close')) $rs->close();

		// print out csv for debugging
		/*
		rewind($buffer);
		$out = fopen('php://output', 'w+b');
		stream_copy_to_stream($buffer, $out);
		fclose($out);
		*/

		// send the data
		rewind($buffer);
		print $this->remote_call('PUT', $target, $buffer);
		fclose($buffer);
		return $record_count;
	}

}

