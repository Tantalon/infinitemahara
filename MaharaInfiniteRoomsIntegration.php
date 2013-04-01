<?php

require_once(dirname(__FILE__) . '/InfiniteRoomsIntegration.php');

class MaharaInfiniteRoomsIntegration extends InfiniteRoomsIntegration {

	protected function get_config($key) {
		return get_config_plugin('export', 'infiniterooms', $key);
	}

	protected function set_config($key, $value) {
		set_config_plugin('export', 'infiniterooms', $key, $value);
	}

	protected function get_site_name() {
		$cn = get_config('wwwroot');
		if (empty($cn)) $cn = parent::get_site_name();
		return $cn;
	}

	protected function get_site_contact() {
		$email = get_config('emailcontact');
		if (empty($email)) $email = parent::get_site_contact();
		return $email;
	}

	public function get_log_size() {
		return count_records('artefact')
			+ count_records('notification_internal_activity')
			+ count_records('interaction_forum_post');
	}

	public function get_log_done() {
		$server_time = $this->get_last_sync() + $this->utc_to_local();
		
		return count_records_select('artefact', "mtime <= ?", array($server_time))
			+ count_records_select('notification_internal_activity', "ctime <= ?", array($server_time))
			+ count_records_select('interaction_forum_post', "ctime <= ?", array($server_time));
	}

	protected function get_users($since_time) {
		$since_time += $this->utc_to_local();
		$user_table = db_table_name('usr');
		
		return $this->query("
			SELECT id as sysid,
			username,
			concat_ws(' ', firstname, lastname) as name
			FROM $user_table
			WHERE ctime >= ?
		", array($since_time));
	}

	protected function get_modules($since_time) {
		$since_time += $this->utc_to_local();
		$artefact_table = db_table_name('artefact');
		$artefact_installed_type_table = db_table_name('artefact_installed_type');
		$interaction_forum_topic_table = db_table_name('interaction_forum_topic');
		$interaction_instance_table = db_table_name('interaction_instance');
		$view_table = db_table_name('view');
		
		return $this->query("
			(
				SELECT 'message' as sysid, 'message' as name
			) UNION ALL (
				SELECT concat_ws('_', artefacttype, a.id) as sysid,
					a.title as name
				FROM $artefact_table a
				INNER JOIN $artefact_installed_type_table atype ON atype.name = a.artefacttype
				WHERE a.mtime >= ? and atype.plugin != 'internal'
			) UNION ALL (
				SELECT concat_ws('_', 'forumtopic', ft.id) AS sysid,
					concat_ws(': ', i.title, i.description) AS name
				FROM $interaction_forum_topic_table ft
				INNER JOIN $interaction_instance_table i ON i.plugin = 'forum' AND i.id = ft.forum
				WHERE i.ctime >= ?
			) UNION ALL (
				SELECT concat_ws('_', 'page', v.id) AS sysid,
					v.title AS name
				FROM $view_table v
				WHERE v.mtime >= ?
			)
		", array($since_time, $since_time, $since_time));
	}

	protected function get_actions($since_time, $limit) {
		$local_to_utc = $this->local_to_utc();
		$since_time += $this->utc_to_local();
		$artefact_table = db_table_name('artefact');
		$artefact_installed_type_table = db_table_name('artefact_installed_type');
		$notification_internal_activity_table = db_table_name('notification_internal_activity');
		$interaction_forum_post_table = db_table_name('interaction_forum_post');
		$interaction_forum_topic_table = db_table_name('interaction_forum_topic');
		$interaction_instance_table = db_table_name('interaction_instance');
		$view_table = db_table_name('view');

		return $this->query("
			(
				SELECT date_format(m.ctime + interval $local_to_utc second, '%Y-%m-%dT%H:%i:%sZ') as time,
					'send message' as action,
					m.usr as user,
					'message' AS module
				FROM $notification_internal_activity_table m
				WHERE m.ctime >= ? and m.type = 2
			) UNION ALL (
				SELECT date_format(a.mtime + interval $local_to_utc second, '%Y-%m-%dT%H:%i:%sZ') as time,
					case when a.ctime = a.mtime then 'create' else 'update' end as action,
					a.author as user,
					concat_ws('_', atype.plugin, a.id) as module
				FROM $artefact_table a
				LEFT JOIN $artefact_installed_type_table atype ON atype.name = a.artefacttype
				WHERE a.mtime >= ?
			) UNION ALL (
				SELECT date_format(fp.ctime + interval $local_to_utc second, '%Y-%m-%dT%H:%i:%sZ') as time,
					'post' as action,
					fp.poster as user,
					concat_ws('_', 'forumtopic', ft.id) AS module
				FROM $interaction_forum_topic_table ft
				INNER JOIN $interaction_instance_table i ON i.plugin = 'forum' AND i.id = ft.forum
				INNER JOIN $interaction_forum_post_table fp ON fp.topic = ft.id
				WHERE fp.ctime >= ?
			) UNION ALL (
				SELECT date_format(v.mtime + interval $local_to_utc second, '%Y-%m-%dT%H:%i:%sZ') as time,
					case when v.ctime = v.mtime then 'create' else 'update' end as action,
					v.owner as user,
					concat_ws('_', 'page', v.id) as module
				FROM ep_view v
				WHERE v.mtime >= ?
			)
			ORDER BY time LIMIT $limit
		", array($since_time, $since_time, $since_time, $since_time));
	}
	
	protected function utc_to_local() {
		$y = date('Y');
		$offset = mktime(0,0,0,12,2,$y,0) - gmmktime(0,0,0,12,2,$y,0);
		return $offset;
	}

	protected function local_to_utc() {
		return - $this->utc_to_local();
	}

	protected function query($query, $params) {
		$rs = get_recordset_sql($query, $params);
		if (!$rs) {
			$rs->close();
			$rs = false;
		}
		return $rs;
	}

}

